<?php

namespace App\Services;

use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Events\ProjectVersionReleased;
use App\Exceptions\DomainConflictException;
use App\Http\Middleware\AuditLogger;
use App\Models\Defect;
use App\Models\ProjectVersion;
use App\Models\ProjectVersionHistory;
use App\Models\ProjectVersionReleaseSnapshot;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use App\Policies\ProjectVersionPolicy;
use App\ValueObjects\ReleaseCommand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ProjectReleaseService
{
    private const NORMAL_STATUSES = [
        ProjectVersionStatus::READY_TO_RELEASE,
    ];

    private const FORCE_STATUSES = [
        ProjectVersionStatus::IN_TESTING,
        ProjectVersionStatus::READY_TO_RELEASE,
    ];

    private const MAX_SCOPE_RETRIES = 5;

    private static int $snapshotCreationDepth = 0;

    public function __construct(
        private readonly ReleaseGateService $releaseGateService,
        private readonly ProjectVersionPolicy $policy,
        private readonly VersionGateLock $versionGateLock,
    ) {}

    public function release(ProjectVersion $version, User $actor, array $data): ProjectVersion
    {
        $command = ReleaseCommand::from($data);

        for ($attempt = 1; $attempt <= self::MAX_SCOPE_RETRIES; $attempt++) {
            try {
                return DB::transaction(
                    fn (): ProjectVersion => $this->releaseLocked($version->id, $actor, $command),
                );
            } catch (ReleaseScopeChanged $exception) {
                if ($attempt === self::MAX_SCOPE_RETRIES) {
                    throw new DomainConflictException(
                        'VERSION_SCOPE_CHANGED',
                        errors: ['project_version_id' => [$version->id]],
                        message: 'The version scope changed during release. Reload and try again.',
                    );
                }
            }
        }

        throw new RuntimeException('Unreachable project release retry state.');
    }

    public static function isSnapshotCreationAuthorized(): bool
    {
        return self::$snapshotCreationDepth > 0;
    }

    private function releaseLocked(
        int $versionId,
        User $actor,
        ReleaseCommand $command,
    ): ProjectVersion {
        $initialLinks = RequirementProject::query()
            ->where('project_version_id', $versionId)
            ->orderBy('id')
            ->get(['id', 'requirement_id', 'project_id']);

        $this->versionGateLock->acquireScopes(
            $initialLinks->map(static fn (RequirementProject $link): array => [
                'requirement_id' => $link->requirement_id,
                'project_id' => $link->project_id,
            ]),
        );
        $this->versionGateLock->acquire($versionId);

        $links = RequirementProject::query()
            ->whereKey($initialLinks->pluck('id')->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $locked = ProjectVersion::query()
            ->whereKey($versionId)
            ->lockForUpdate()
            ->firstOrFail();
        $liveLinks = RequirementProject::query()
            ->where('project_version_id', $locked->id)
            ->where('project_id', $locked->project_id)
            ->orderBy('id')
            ->get(['id', 'requirement_id', 'project_id']);

        $initialScope = $initialLinks->map(static fn (RequirementProject $link): array => [
            'id' => $link->id,
            'requirement_id' => $link->requirement_id,
            'project_id' => $link->project_id,
        ])->values()->all();
        $liveScope = $liveLinks->map(static fn (RequirementProject $link): array => [
            'id' => $link->id,
            'requirement_id' => $link->requirement_id,
            'project_id' => $link->project_id,
        ])->values()->all();

        if ($initialScope !== $liveScope) {
            throw new ReleaseScopeChanged;
        }
        $liveScopeIds = $liveLinks->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $force = $command->force;
        $reason = $command->forceReason ?? '';
        $from = $locked->status;

        if ($from === ProjectVersionStatus::RELEASED) {
            $force
                ? $this->assertForceActor($locked, $actor)
                : $this->assertNormalActor($locked, $actor);

            $snapshot = $locked->releaseSnapshot()->first();
            if ($snapshot === null) {
                throw new DomainConflictException(
                    'RELEASE_SNAPSHOT_MISSING',
                    errors: ['project_version_id' => [$locked->id]],
                    message: 'The released version has no immutable snapshot.',
                );
            }

            if ($snapshot->is_override !== $force) {
                throw new DomainConflictException(
                    'RELEASE_MODE_MISMATCH',
                    errors: ['force' => ['expected' => $snapshot->is_override]],
                    message: 'The release replay mode does not match the original release.',
                );
            }

            return $locked->fresh();
        }

        if ($force) {
            $this->assertForceRelease($locked, $actor, $reason);
        } else {
            $this->assertNormalActor($locked, $actor);
            $this->assertNormalStatus($locked);
        }

        $this->assertExpectedLock($locked, $command->lockVersion);

        $notes = $command->hasReleaseNotes
            ? ($command->releaseNotes ?? '')
            : trim((string) $locked->release_notes);
        $locked->release_notes = $notes === '' ? null : $notes;
        $locked->save();

        $gate = $this->releaseGateService->check($locked, ProjectVersionStatus::RELEASED);
        if (! $force && ! $gate->passed) {
            throw new DomainConflictException(
                'RELEASE_GATE_FAILED',
                errors: $gate->blocking,
                message: 'The version release gates are not satisfied.',
            );
        }

        $gateResult = [
            ...$gate->jsonSerialize(),
            'original_status' => $from->value,
        ];
        $releasedAt = now();
        $requirementIds = $links->pluck('requirement_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $snapshot = $this->createSnapshot([
            'project_version_id' => $locked->id,
            'requirement_scope' => $links->map(static fn (RequirementProject $link): array => [
                'requirement_project_id' => $link->id,
                'requirement_id' => $link->requirement_id,
                'project_id' => $link->project_id,
                'pre_release_delivery_status' => $link->delivery_status->value,
                'delivery_status' => ProjectDeliveryStatus::DEPLOYED->value,
            ])->values()->all(),
            'task_count' => $this->scopeTaskCount($locked, $requirementIds),
            'defect_count' => $this->scopeDefectCount($locked, $requirementIds),
            'gate_result' => $gateResult,
            'release_notes' => $locked->release_notes,
            'is_override' => $force,
            'override_reason' => $force ? $reason : null,
            'released_by_id' => $actor->id,
            'released_at' => $releasedAt,
        ]);

        $this->versionGateLock->runAuthorizedRequirementProjectMutations(
            $liveScopeIds,
            function () use ($links): void {
                foreach ($links as $link) {
                    $link->update(['delivery_status' => ProjectDeliveryStatus::DEPLOYED]);
                }
            },
            releaseVersionId: $locked->id,
        );

        foreach ($requirementIds as $requirementId) {
            $this->recalculateRequirement($requirementId);
        }

        $locked->status = ProjectVersionStatus::RELEASED;
        $locked->released_at = $releasedAt;
        $locked->released_by_id = $actor->id;
        $locked->lock_version++;
        $locked->save();

        ProjectVersionHistory::query()->create([
            'project_version_id' => $locked->id,
            'event_type' => $force ? 'force_release' : 'release',
            'from_status' => $from,
            'to_status' => ProjectVersionStatus::RELEASED,
            'actor_id' => $actor->id,
            'reason' => $force ? $reason : null,
            'metadata' => [
                'is_override' => $force,
                'snapshot_id' => $snapshot->id,
                'requirement_project_ids' => $liveScopeIds,
                'failed_gates' => $gate->blocking,
                'snapshot_payload' => [
                    'requirement_scope' => $snapshot->requirement_scope,
                    'task_count' => $snapshot->task_count,
                    'defect_count' => $snapshot->defect_count,
                    'gate_result' => $snapshot->gate_result,
                    'release_notes' => $snapshot->release_notes,
                ],
            ],
            'created_at' => $releasedAt,
        ]);

        AuditLogger::log($actor->id, [
            'user_name' => $actor->username,
            'user_display_name' => $actor->display_name,
            'user_type' => $actor->user_type,
            'module' => 1,
            'action_type' => 4,
            'target_type' => 'project_version',
            'target_id' => $locked->id,
            'target_name' => $locked->code,
            'detail' => [
                'event' => $force ? 'force_release' : 'release',
                'is_override' => $force,
                'override_reason' => $force ? $reason : null,
                'snapshot_id' => $snapshot->id,
                'from_status' => $from->value,
                'to_status' => ProjectVersionStatus::RELEASED->value,
                'failed_gates' => $gate->blocking,
            ],
        ]);

        $event = new ProjectVersionReleased(
            $locked->id,
            $locked->project_id,
            $actor->id,
            $force,
        );
        DB::afterCommit(static fn (): mixed => event($event));

        return $locked->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSnapshot(array $attributes): ProjectVersionReleaseSnapshot
    {
        DB::select(
            <<<'SQL'
                SELECT set_config(
                    'itpms.release_snapshot_version_id',
                    ?,
                    true
                )
                SQL,
            [(string) $attributes['project_version_id']],
        );
        self::$snapshotCreationDepth++;

        try {
            /** @var ProjectVersionReleaseSnapshot $snapshot */
            $snapshot = ProjectVersionReleaseSnapshot::query()->create($attributes);
        } catch (QueryException $exception) {
            self::$snapshotCreationDepth--;

            throw $exception;
        } catch (Throwable $exception) {
            self::$snapshotCreationDepth--;
            $this->clearSnapshotAuthorization();

            throw $exception;
        }

        self::$snapshotCreationDepth--;
        $this->clearSnapshotAuthorization();

        return $snapshot;
    }

    private function clearSnapshotAuthorization(): void
    {
        DB::select(
            <<<'SQL'
                SELECT set_config(
                    'itpms.release_snapshot_version_id',
                    '',
                    true
                )
                SQL,
        );
    }

    private function assertNormalActor(ProjectVersion $version, User $actor): void
    {
        if (! $this->policy->release($actor, $version)) {
            throw new DomainConflictException(
                'VERSION_RELEASE_FORBIDDEN',
                403,
                ['actor_id' => [$actor->id]],
                'Only the assigned internal IT project manager may release this version.',
            );
        }
    }

    private function assertForceActor(ProjectVersion $version, User $actor): void
    {
        if ($this->policy->forceRelease($actor, $version)) {
            return;
        }

        throw new DomainConflictException(
            'FORCE_RELEASE_FORBIDDEN',
            403,
            ['actor_id' => [$actor->id]],
            'Only a super administrator may force release a version.',
        );
    }

    private function assertForceRelease(
        ProjectVersion $version,
        User $actor,
        string $reason,
    ): void {
        $this->assertForceActor($version, $actor);

        if ($reason === '') {
            throw new DomainConflictException(
                'FORCE_REASON_REQUIRED',
                422,
                ['reason' => ['A reason is required.']],
            );
        }

        if (! in_array($version->status, self::FORCE_STATUSES, true)) {
            throw new DomainConflictException(
                'INVALID_FORCE_RELEASE_STATUS',
                errors: ['status' => [
                    'current' => $version->status->value,
                    'allowed' => array_map(
                        static fn (ProjectVersionStatus $status): int => $status->value,
                        self::FORCE_STATUSES,
                    ),
                ]],
                message: 'The current version status cannot be force released.',
            );
        }
    }

    private function assertNormalStatus(ProjectVersion $version): void
    {
        if (! in_array($version->status, self::NORMAL_STATUSES, true)) {
            throw new DomainConflictException(
                'INVALID_RELEASE_STATUS',
                errors: ['status' => [
                    'current' => $version->status->value,
                    'allowed' => array_map(
                        static fn (ProjectVersionStatus $status): int => $status->value,
                        self::NORMAL_STATUSES,
                    ),
                ]],
                message: 'The version is not ready to release.',
            );
        }
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

    private function recalculateRequirement(int $requirementId): void
    {
        $requirement = Requirement::query()
            ->whereKey($requirementId)
            ->lockForUpdate()
            ->firstOrFail();
        $minimum = RequirementProject::query()
            ->where('requirement_id', $requirementId)
            ->min('delivery_status');

        if ($minimum === null) {
            throw new DomainConflictException(
                'REQUIREMENT_SCOPE_MISSING',
                errors: ['requirement_id' => [$requirementId]],
            );
        }

        if ($requirement->status !== (int) $minimum) {
            $requirement->status = (int) $minimum;
            $requirement->save();
        }
    }

    private function scopeTaskCount(ProjectVersion $version, Collection $requirementIds): int
    {
        if ($requirementIds->isEmpty()) {
            return 0;
        }

        return Task::query()
            ->where('project_id', $version->project_id)
            ->whereIn('requirement_id', $requirementIds)
            ->count();
    }

    private function scopeDefectCount(ProjectVersion $version, Collection $requirementIds): int
    {
        if ($requirementIds->isEmpty()) {
            return 0;
        }

        return Defect::query()
            ->where('project_id', $version->project_id)
            ->whereIn('requirement_id', $requirementIds)
            ->count();
    }
}

final class ReleaseScopeChanged extends RuntimeException {}
