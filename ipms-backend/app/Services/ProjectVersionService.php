<?php

namespace App\Services;

use App\Enums\ProjectVersionStatus;
use App\Exceptions\DomainConflictException;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\ProjectVersionHistory;
use App\Models\RequirementProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProjectVersionService
{
    private const EDITABLE_FIELDS = [
        'code',
        'name',
        'description',
        'owner_id',
        'planned_start_date',
        'planned_release_date',
        'release_notes',
    ];

    private const SCOPE_LOCKED_STATUSES = [
        ProjectVersionStatus::READY_TO_RELEASE,
        ProjectVersionStatus::RELEASED,
        ProjectVersionStatus::ARCHIVED,
    ];

    public function create(Project $project, array $data, User $actor): ProjectVersion
    {
        try {
            return DB::transaction(function () use ($project, $data, $actor): ProjectVersion {
                return ProjectVersion::query()->create([
                    ...Arr::only($data, self::EDITABLE_FIELDS),
                    'project_id' => $project->id,
                    'status' => ProjectVersionStatus::DRAFT,
                    'lock_version' => 1,
                    'created_by_id' => $actor->id,
                ])->fresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->throwVersionCodeConflict($exception);
        }
    }

    public function update(
        ProjectVersion $version,
        array $data,
        int $expectedLock,
        User $actor,
    ): ProjectVersion {
        try {
            return DB::transaction(function () use ($version, $data, $expectedLock): ProjectVersion {
                $locked = $this->lockVersion($version->id);
                $this->assertExpectedLock($locked, $expectedLock);
                $this->assertScopeMutable($locked);

                $locked->fill(Arr::only($data, self::EDITABLE_FIELDS));
                $locked->lock_version++;
                $locked->save();

                return $locked->fresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->throwVersionCodeConflict($exception);
        }
    }

    public function transition(
        ProjectVersion $version,
        ProjectVersionStatus $target,
        int $expectedLock,
        User $actor,
        ?string $reason = null,
    ): ProjectVersion {
        return DB::transaction(function () use (
            $version,
            $target,
            $expectedLock,
            $actor,
            $reason,
        ): ProjectVersion {
            $locked = $this->lockVersion($version->id);
            $this->assertExpectedLock($locked, $expectedLock);

            if ($target === ProjectVersionStatus::RELEASED) {
                throw new DomainConflictException(
                    'RELEASE_ACTION_REQUIRED',
                    errors: ['status' => ['requested' => $target->value]],
                    message: 'Released status must be created by the release action.',
                );
            }

            if (! in_array($target, $locked->status->allowedTransitions(), true)) {
                throw new DomainConflictException(
                    'INVALID_VERSION_TRANSITION',
                    errors: ['status' => [
                        'current' => $locked->status->value,
                        'requested' => $target->value,
                    ]],
                    message: 'The requested version transition is not allowed.',
                );
            }

            $rollback = $target->value < $locked->status->value;
            if ($rollback) {
                $this->assertReason($reason);
            }

            $from = $locked->status;
            $locked->status = $target;
            $locked->lock_version++;
            $locked->save();

            $this->history(
                $locked,
                $rollback ? 'status_rollback' : 'status_forward',
                $actor,
                $reason,
                $from,
                $target,
            );

            return $locked->fresh();
        });
    }

    public function assignRequirement(
        ProjectVersion $version,
        RequirementProject $link,
        int $expectedLock,
        User $actor,
        ?string $reason = null,
    ): ProjectVersion {
        return DB::transaction(function () use (
            $version,
            $link,
            $expectedLock,
            $actor,
            $reason,
        ): ProjectVersion {
            [$lockedLink, $versions] = $this->lockScopeMutationRows($version, $link);
            $target = $versions->get($version->id);

            if (! $target instanceof ProjectVersion) {
                throw new DomainConflictException(
                    'VERSION_PROJECT_MISMATCH',
                    errors: ['project_version_id' => [
                        'expected' => $version->id,
                        'current' => $lockedLink->project_version_id,
                    ]],
                );
            }

            $this->assertExpectedLock($target, $expectedLock);
            $this->assertMatchingProject($target, $lockedLink);
            $this->assertScopeMutable($target);

            $oldVersionId = $lockedLink->project_version_id;
            $oldVersion = $oldVersionId === null ? null : $versions->get($oldVersionId);
            if ($oldVersion instanceof ProjectVersion) {
                $this->assertScopeMutable($oldVersion);
            }

            if ($target->status === ProjectVersionStatus::IN_TESTING
                || $oldVersion?->status === ProjectVersionStatus::IN_TESTING) {
                $this->assertReason($reason);
            }

            if ($oldVersionId === $target->id) {
                return $target->fresh();
            }

            $lockedLink->update([
                'project_version_id' => $target->id,
                'version_assigned_by_id' => $actor->id,
                'version_assigned_at' => now(),
            ]);

            if ($oldVersion instanceof ProjectVersion && $oldVersion->id !== $target->id) {
                $oldVersion->lock_version++;
                $oldVersion->save();
            }

            $target->lock_version++;
            $target->save();

            $this->history(
                $target,
                $oldVersionId === null ? 'requirement_added' : 'requirement_moved',
                $actor,
                $reason,
                metadata: $this->scopeMetadata($lockedLink, $oldVersionId, $target->id),
            );

            return $target->fresh();
        });
    }

    public function unassignRequirement(
        ProjectVersion $version,
        RequirementProject $link,
        int $expectedLock,
        User $actor,
        ?string $reason = null,
    ): ProjectVersion {
        return DB::transaction(function () use (
            $version,
            $link,
            $expectedLock,
            $actor,
            $reason,
        ): ProjectVersion {
            [$lockedLink, $versions] = $this->lockScopeMutationRows($version, $link);
            $target = $versions->get($version->id);

            if (! $target instanceof ProjectVersion) {
                throw new DomainConflictException(
                    'VERSION_PROJECT_MISMATCH',
                    errors: ['project_version_id' => [
                        'expected' => $version->id,
                        'current' => $lockedLink->project_version_id,
                    ]],
                );
            }

            $this->assertExpectedLock($target, $expectedLock);
            $this->assertMatchingProject($target, $lockedLink);

            if ($lockedLink->project_version_id !== $target->id) {
                throw new DomainConflictException(
                    'VERSION_PROJECT_MISMATCH',
                    errors: ['project_version_id' => [
                        'expected' => $target->id,
                        'current' => $lockedLink->project_version_id,
                    ]],
                    message: 'The requirement is not assigned to this version.',
                );
            }

            $this->assertScopeMutable($target);
            if ($target->status === ProjectVersionStatus::IN_TESTING) {
                $this->assertReason($reason);
            }

            $lockedLink->update([
                'project_version_id' => null,
                'version_assigned_by_id' => null,
                'version_assigned_at' => null,
            ]);

            $target->lock_version++;
            $target->save();

            $this->history(
                $target,
                'requirement_removed',
                $actor,
                $reason,
                metadata: $this->scopeMetadata($lockedLink, $target->id, null),
            );

            return $target->fresh();
        });
    }

    public function deleteDraft(
        ProjectVersion $version,
        int $expectedLock,
        User $actor,
    ): void {
        DB::transaction(function () use ($version, $expectedLock): void {
            $locked = $this->lockVersion($version->id);
            $this->assertExpectedLock($locked, $expectedLock);

            if ($locked->status !== ProjectVersionStatus::DRAFT) {
                $this->throwLockedStatus($locked);
            }

            if ($locked->requirementLinks()->exists()) {
                throw new DomainConflictException(
                    'VERSION_LOCKED',
                    errors: ['reason' => ['requirements_assigned']],
                    message: 'A version with assigned requirements cannot be deleted.',
                );
            }

            if ($locked->histories()->exists()) {
                throw new DomainConflictException(
                    'VERSION_LOCKED',
                    errors: ['reason' => ['history_exists']],
                    message: 'A version with history cannot be deleted.',
                );
            }

            $locked->delete();
        });
    }

    private function lockVersion(int $id): ProjectVersion
    {
        return ProjectVersion::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<int, int|null>  $ids
     * @return Collection<int, ProjectVersion>
     */
    private function lockVersions(array $ids): Collection
    {
        $ids = collect($ids)
            ->filter(fn (?int $id): bool => $id !== null)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return ProjectVersion::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Global release-workflow lock order: requirement_project.id ASC,
     * then project_versions.id ASC. ProjectReleaseService must use the same order.
     *
     * @return array{RequirementProject, Collection<int, ProjectVersion>}
     */
    private function lockScopeMutationRows(
        ProjectVersion $version,
        RequirementProject $link,
    ): array {
        $lockedLink = RequirementProject::query()
            ->whereKey($link->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->firstOrFail();

        $versions = $this->lockVersions([
            $version->id,
            $lockedLink->project_version_id,
        ]);

        return [$lockedLink, $versions];
    }

    private function assertExpectedLock(ProjectVersion $version, int $expectedLock): void
    {
        if ($version->lock_version !== $expectedLock) {
            throw new DomainConflictException(
                'STALE_VERSION',
                errors: ['lock_version' => ['current' => $version->lock_version]],
                message: 'The version has changed. Reload it and try again.',
            );
        }
    }

    private function assertScopeMutable(ProjectVersion $version): void
    {
        if (in_array($version->status, self::SCOPE_LOCKED_STATUSES, true)) {
            $this->throwLockedStatus($version);
        }
    }

    private function throwLockedStatus(ProjectVersion $version): never
    {
        throw new DomainConflictException(
            'VERSION_LOCKED',
            errors: ['status' => ['current' => $version->status->value]],
            message: 'The current version status is immutable.',
        );
    }

    private function assertMatchingProject(
        ProjectVersion $version,
        RequirementProject $link,
    ): void {
        if ($version->project_id !== $link->project_id) {
            throw new DomainConflictException(
                'VERSION_PROJECT_MISMATCH',
                errors: ['project_id' => [
                    'version' => $version->project_id,
                    'requirement' => $link->project_id,
                ]],
                message: 'The version and requirement must belong to the same project.',
            );
        }
    }

    private function assertReason(?string $reason): void
    {
        if (blank($reason)) {
            throw new DomainConflictException(
                'FORCE_REASON_REQUIRED',
                422,
                ['reason' => ['A reason is required.']],
                'A reason is required.',
            );
        }
    }

    /**
     * @return array<string, int|null>
     */
    private function scopeMetadata(
        RequirementProject $link,
        ?int $oldVersionId,
        ?int $newVersionId,
    ): array {
        return [
            'requirement_project_id' => $link->id,
            'requirement_id' => $link->requirement_id,
            'old_version_id' => $oldVersionId,
            'new_version_id' => $newVersionId,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function history(
        ProjectVersion $version,
        string $eventType,
        User $actor,
        ?string $reason = null,
        ?ProjectVersionStatus $from = null,
        ?ProjectVersionStatus $to = null,
        array $metadata = [],
    ): void {
        ProjectVersionHistory::query()->create([
            'project_version_id' => $version->id,
            'event_type' => $eventType,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
            'reason' => filled($reason) ? trim($reason) : null,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    private function throwVersionCodeConflict(
        UniqueConstraintViolationException $exception,
    ): never {
        if (! str_contains($this->exceptionText($exception), 'ux_project_versions_project_code')) {
            throw $exception;
        }

        throw new DomainConflictException(
            'VERSION_CODE_EXISTS',
            422,
            ['code' => ['The version code is already in use for this project.']],
            'The version code is already in use for this project.',
        );
    }

    private function exceptionText(Throwable $exception): string
    {
        $messages = [];

        do {
            $messages[] = $exception->getMessage();
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return implode(' ', $messages);
    }
}
