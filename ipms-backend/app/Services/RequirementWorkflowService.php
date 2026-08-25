<?php

namespace App\Services;

use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\RequirementStatus;
use App\Exceptions\DomainConflictException;
use App\Http\Middleware\AuditLogger;
use App\Models\ProjectVersion;
use App\Models\ProjectVersionHistory;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\RequirementVersion;
use App\Models\User;
use App\Services\Results\RequirementUpdateResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RequirementWorkflowService
{
    public function __construct(
        private readonly VersionGateLock $versionGateLock,
    ) {}

    private const EDITABLE_FIELDS = [
        'title',
        'description',
        'priority',
        'requirement_type',
        'expected_completion_date',
        'dev_lead_id',
    ];

    public function submit(array $data, User $actor): Requirement
    {
        return DB::transaction(function () use ($data, $actor): Requirement {
            $projectIds = $this->normalizeProjectIds($data['project_ids'] ?? []);
            $requirement = Requirement::query()->create([
                ...Arr::only($data, self::EDITABLE_FIELDS),
                'submitter_id' => $actor->id,
                'submitted_at' => now(),
                'status' => RequirementStatus::PENDING_REVIEW->value,
                'version' => 1,
                'created_by_id' => $actor->id,
            ]);

            foreach ($projectIds as $projectId) {
                $this->createProjectLink($requirement, $projectId);
            }

            $this->audit($actor, $requirement, 1);

            return $this->freshRequirement($requirement);
        });
    }

    public function update(
        Requirement $requirement,
        User $actor,
        array $data,
    ): RequirementUpdateResult {
        return DB::transaction(function () use ($requirement, $actor, $data): RequirementUpdateResult {
            [$locked, $links, $versions] = $this->lockRequirementScope($requirement);

            if ($locked->isRejectedForResubmission()) {
                return RequirementUpdateResult::resubmitted(
                    $this->resubmitLocked($locked, $actor, $data, $links, $versions),
                );
            }

            $projectIds = null;
            $oldProjectIds = null;

            if (array_key_exists('project_ids', $data)) {
                if ($locked->status !== RequirementStatus::PENDING_REVIEW->value) {
                    throw new DomainConflictException(
                        'REQUIREMENT_PROJECT_SCOPE_LOCKED',
                        message: 'Project links cannot be changed after requirement approval.',
                    );
                }

                if ($locked->submitter_id !== $actor->id) {
                    throw new AuthorizationException(
                        'Only the original requester may change linked projects.',
                    );
                }

                $projectIds = $this->normalizeProjectIds($data['project_ids']);
                $oldProjectIds = $this->lockedProjectIds($links);
            }

            $changes = $this->revisionChanges(
                $locked,
                $data,
                $projectIds,
                $oldProjectIds,
            );

            if ($changes !== []) {
                $newVersion = $locked->version + 1;
                $locked->update([
                    ...Arr::only($data, self::EDITABLE_FIELDS),
                    'version' => $newVersion,
                    'updated_by_id' => $actor->id,
                ]);

                if ($projectIds !== null && $oldProjectIds !== $projectIds) {
                    $this->synchronizeProjectLinks($locked, $projectIds, $links, $versions);
                }

                $this->createRevisionSnapshot(
                    $locked,
                    $newVersion,
                    $actor,
                    $changes,
                    'Requirement updated',
                );
            }

            $this->audit($actor, $locked, 2, ['changes' => $changes]);

            return RequirementUpdateResult::updated(
                $this->freshRequirement($locked),
            );
        });
    }

    public function review(
        Requirement $requirement,
        User $reviewer,
        string $action,
        ?string $comment = null,
    ): Requirement {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages([
                'action' => 'The review action must be approve or reject.',
            ]);
        }

        if ($action === 'reject' && blank($comment)) {
            throw ValidationException::withMessages([
                'comment' => 'A rejection comment is required.',
            ]);
        }

        return DB::transaction(function () use ($requirement, $reviewer, $action, $comment): Requirement {
            [$locked, $links, $versions] = $this->lockRequirementScope($requirement);

            if ($locked->status !== RequirementStatus::PENDING_REVIEW->value) {
                throw ValidationException::withMessages([
                    'action' => 'Only pending requirements can be reviewed.',
                ]);
            }

            if ($locked->isRejectedForResubmission()) {
                throw ValidationException::withMessages([
                    'action' => 'The requester must resubmit before another review.',
                ]);
            }

            $locked->update([
                'status' => RequirementStatus::PENDING_REVIEW->value,
                'reviewer_id' => $reviewer->id,
                'review_comment' => $comment ?? 'Approved',
                'reviewed_at' => now(),
                'updated_by_id' => $reviewer->id,
            ]);

            if ($action === 'approve') {
                $this->initializeApprovedProjectsLocked($locked, $reviewer, $links, $versions);
            }

            $this->audit($reviewer, $locked, 5, [
                'action' => $action,
                'comment' => $comment,
            ]);

            return $this->freshRequirement($locked);
        });
    }

    public function resubmit(
        Requirement $requirement,
        User $requester,
        array $data,
    ): Requirement {
        return DB::transaction(function () use ($requirement, $requester, $data): Requirement {
            [$locked, $links, $versions] = $this->lockRequirementScope($requirement);

            return $this->resubmitLocked($locked, $requester, $data, $links, $versions);
        });
    }

    public function initializeApprovedProjects(
        Requirement $requirement,
        User $actor,
    ): RequirementStatus {
        return DB::transaction(function () use ($requirement, $actor): RequirementStatus {
            [$locked, $links, $versions] = $this->lockRequirementScope($requirement);

            return $this->initializeApprovedProjectsLocked($locked, $actor, $links, $versions);
        });
    }

    public function transitionProjectDelivery(
        Requirement $requirement,
        int $projectId,
        ProjectDeliveryStatus|int $targetStatus,
        User $actor,
    ): RequirementProject {
        return DB::transaction(function () use ($requirement, $projectId, $targetStatus, $actor): RequirementProject {
            [$lockedRequirement, $links, $versions] = $this->lockRequirementScope(
                $requirement,
            );

            if ($lockedRequirement->status === RequirementStatus::PENDING_REVIEW->value) {
                throw ValidationException::withMessages([
                    'status' => 'Project delivery cannot advance before approval.',
                ]);
            }

            /** @var RequirementProject|null $link */
            $link = $links->firstWhere('project_id', $projectId);

            if ($link === null) {
                throw ValidationException::withMessages([
                    'project_id' => 'The project is not linked to this requirement.',
                ]);
            }

            Gate::forUser($actor)->authorize('transitionProject', [
                $lockedRequirement,
                $link->project()->firstOrFail(),
            ]);

            if ($link->project_version_id !== null) {
                /** @var ProjectVersion|null $version */
                $version = $versions->get($link->project_version_id);
                if ($version !== null && in_array($version->status, [
                    ProjectVersionStatus::READY_TO_RELEASE,
                    ProjectVersionStatus::RELEASED,
                    ProjectVersionStatus::ARCHIVED,
                ], true)) {
                    throw new DomainConflictException(
                        'VERSION_LOCKED',
                        errors: [
                            'project_version_id' => [$version->id],
                            'status' => ['current' => $version->status->value],
                        ],
                        message: 'The current version status is immutable.',
                    );
                }
            }

            $target = $targetStatus instanceof ProjectDeliveryStatus
                ? $targetStatus
                : $this->deliveryStatusFrom($targetStatus);
            $current = $link->delivery_status;

            if ($current === ProjectDeliveryStatus::PENDING_DEPLOY
                && $target === ProjectDeliveryStatus::DEPLOYED) {
                throw new DomainConflictException(
                    'RELEASE_ACTION_REQUIRED',
                    errors: ['delivery_status' => [
                        'current' => $current->value,
                        'requested' => $target->value,
                    ]],
                    message: 'Deployment status may only be created by the release action.',
                );
            }

            if (! in_array($target, $current->allowedForwardTransitions(), true)) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Delivery cannot move directly from %s to %s.',
                        $current->label(),
                        $target->label(),
                    ),
                ]);
            }

            $link->update(['delivery_status' => $target]);

            if ($link->project_version_id !== null) {
                ProjectVersionHistory::query()->create([
                    'project_version_id' => $link->project_version_id,
                    'event_type' => 'requirement_delivery_status_changed',
                    'from_status' => null,
                    'to_status' => null,
                    'actor_id' => $actor->id,
                    'metadata' => [
                        'requirement_id' => $lockedRequirement->id,
                        'project_id' => $projectId,
                        'requirement_project_id' => $link->id,
                        'from_delivery_status' => $current->value,
                        'to_delivery_status' => $target->value,
                    ],
                    'created_at' => now(),
                ]);
            }

            $this->recalculateAggregateStatusLocked($lockedRequirement, $links);
            $this->audit($actor, $lockedRequirement, 4, [
                'project_id' => $projectId,
                'to_delivery_status' => $target->value,
            ]);

            return $link->fresh();
        });
    }

    public function recalculateAggregateStatus(Requirement $requirement): RequirementStatus
    {
        return DB::transaction(function () use ($requirement): RequirementStatus {
            [$locked, $links] = $this->lockRequirementScope($requirement);

            return $this->recalculateAggregateStatusLocked($locked, $links);
        });
    }

    private function resubmitLocked(
        Requirement $locked,
        User $requester,
        array $data,
        Collection $links,
        Collection $versions,
    ): Requirement {
        if ($locked->submitter_id !== $requester->id) {
            throw new AuthorizationException(
                'Only the original requester may resubmit this requirement.',
            );
        }
        $this->assertVersionScopeMutable($links, $versions);

        if (! $locked->isRejectedForResubmission()) {
            throw ValidationException::withMessages([
                'requirement' => 'Only a rejected requirement may be resubmitted.',
            ]);
        }

        $oldProjectIds = $this->lockedProjectIds($links);
        $projectIds = array_key_exists('project_ids', $data)
            ? $this->normalizeProjectIds($data['project_ids'])
            : $oldProjectIds;
        $changes = $this->revisionChanges($locked, $data, $projectIds, $oldProjectIds);
        $newVersion = $locked->version + 1;

        $locked->update([
            ...Arr::only($data, self::EDITABLE_FIELDS),
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => null,
            'review_comment' => null,
            'reviewed_at' => null,
            'submitted_at' => now(),
            'version' => $newVersion,
            'updated_by_id' => $requester->id,
        ]);

        $this->synchronizeProjectLinks($locked, $projectIds, $links, $versions);
        $this->createRevisionSnapshot(
            $locked,
            $newVersion,
            $requester,
            $changes,
            'Requirement resubmitted',
        );
        $this->audit($requester, $locked, 2, [
            'version' => $newVersion,
            'changes' => $changes,
        ]);

        return $this->freshRequirement($locked);
    }

    private function initializeApprovedProjectsLocked(
        Requirement $locked,
        User $actor,
        Collection $links,
        Collection $versions,
    ): RequirementStatus {
        if ($links->isEmpty()) {
            throw ValidationException::withMessages([
                'project_ids' => 'An approved requirement must have at least one project.',
            ]);
        }

        $this->assertVersionScopeMutable($links, $versions);

        foreach ($links as $link) {
            $link->update(['delivery_status' => ProjectDeliveryStatus::ASSIGNED]);
        }

        $locked->update([
            'status' => RequirementStatus::ASSIGNED->value,
            'updated_by_id' => $actor->id,
        ]);

        return RequirementStatus::ASSIGNED;
    }

    private function recalculateAggregateStatusLocked(
        Requirement $locked,
        Collection $links,
    ): RequirementStatus {
        if ($locked->status === RequirementStatus::PENDING_REVIEW->value) {
            return RequirementStatus::PENDING_REVIEW;
        }

        if ($links->isEmpty()) {
            throw ValidationException::withMessages([
                'project_ids' => 'An approved requirement must have at least one project.',
            ]);
        }

        $minimum = $links->min(
            static fn (RequirementProject $link): int => $link->delivery_status->value,
        );
        $status = RequirementStatus::from($minimum);

        if ($locked->status !== $status->value) {
            $locked->update(['status' => $status->value]);
        }

        return $status;
    }

    /**
     * Lock order: version gate advisory locks, requirement_project rows,
     * project_versions rows, then the requirement row.
     *
     * @return array{Requirement, Collection<int, RequirementProject>, Collection<int, ProjectVersion>}
     */
    private function lockRequirementScope(Requirement $requirement): array
    {
        $initialLinks = RequirementProject::query()
            ->where('requirement_id', $requirement->getKey())
            ->orderBy('id')
            ->get(['id', 'project_version_id']);
        $initialLinkIds = $initialLinks->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $initialVersionIds = $initialLinks->pluck('project_version_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        foreach ($initialVersionIds as $versionId) {
            $this->versionGateLock->acquire($versionId);
        }

        $links = RequirementProject::query()
            ->whereKey($initialLinkIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $lockedVersionIds = $links->pluck('project_version_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        if ($lockedVersionIds->values()->all() !== $initialVersionIds->values()->all()) {
            throw new DomainConflictException(
                'REQUIREMENT_SCOPE_CHANGED',
                errors: ['requirement_id' => [$requirement->getKey()]],
                message: 'The requirement scope changed. Reload and try again.',
            );
        }

        $versions = ProjectVersion::query()
            ->whereKey($lockedVersionIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $locked = $this->lockRequirement($requirement);
        $liveLinkIds = RequirementProject::query()
            ->where('requirement_id', $locked->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($liveLinkIds !== $initialLinkIds) {
            throw new DomainConflictException(
                'REQUIREMENT_SCOPE_CHANGED',
                errors: ['requirement_id' => [$locked->id]],
                message: 'The requirement scope changed. Reload and try again.',
            );
        }

        return [$locked, $links, $versions];
    }

    private function lockRequirement(Requirement $requirement): Requirement
    {
        return Requirement::query()
            ->whereKey($requirement->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return list<int>
     */
    private function normalizeProjectIds(array $projectIds): array
    {
        $normalized = collect($projectIds)
            ->map(static fn (mixed $projectId): int => (int) $projectId)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'project_ids' => 'At least one project is required.',
            ]);
        }

        return $normalized;
    }

    /**
     * @return list<int>
     */
    private function lockedProjectIds(Collection $links): array
    {
        return $links
            ->pluck('project_id')
            ->map(static fn (mixed $projectId): int => (int) $projectId)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>|null  $projectIds
     * @param  list<int>|null  $oldProjectIds
     * @return list<array{field: string, old_value: mixed, new_value: mixed}>
     */
    private function revisionChanges(
        Requirement $requirement,
        array $data,
        ?array $projectIds,
        ?array $oldProjectIds,
    ): array {
        $changes = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $candidate = clone $requirement;
            $candidate->setAttribute($field, $data[$field]);
            $oldValue = $requirement->getAttribute($field);
            $newValue = $candidate->getAttribute($field);

            if ($candidate->isDirty($field)) {
                $changes[] = [
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                ];
            }
        }

        if ($projectIds !== null && $oldProjectIds !== $projectIds) {
            $changes[] = [
                'field' => 'project_ids',
                'old_value' => $oldProjectIds,
                'new_value' => $projectIds,
            ];
        }

        return $changes;
    }

    /**
     * @param  list<int>  $projectIds
     */
    private function synchronizeProjectLinks(
        Requirement $requirement,
        array $projectIds,
        Collection $lockedLinks,
        Collection $versions,
    ): void {
        $this->assertVersionScopeMutable($lockedLinks, $versions);
        $links = $lockedLinks->keyBy('project_id');

        foreach ($links as $projectId => $link) {
            if (! in_array((int) $projectId, $projectIds, true)) {
                $link->delete();

                continue;
            }

            $link->update(['delivery_status' => ProjectDeliveryStatus::ASSIGNED]);
        }

        foreach ($projectIds as $projectId) {
            if (! $links->has($projectId)) {
                $this->createProjectLink($requirement, $projectId);
            }
        }
    }

    private function assertVersionScopeMutable(
        Collection $links,
        Collection $versions,
    ): void {
        foreach ($links as $link) {
            if ($link->project_version_id === null) {
                continue;
            }

            /** @var ProjectVersion|null $version */
            $version = $versions->get($link->project_version_id);
            if ($version !== null && in_array($version->status, [
                ProjectVersionStatus::READY_TO_RELEASE,
                ProjectVersionStatus::RELEASED,
                ProjectVersionStatus::ARCHIVED,
            ], true)) {
                throw new DomainConflictException(
                    'VERSION_LOCKED',
                    errors: [
                        'project_version_id' => [$version->id],
                        'status' => ['current' => $version->status->value],
                    ],
                    message: 'The current version status is immutable.',
                );
            }
        }
    }

    private function createProjectLink(Requirement $requirement, int $projectId): void
    {
        RequirementProject::query()->create([
            'requirement_id' => $requirement->id,
            'project_id' => $projectId,
            'delivery_status' => ProjectDeliveryStatus::ASSIGNED,
        ]);
    }

    /**
     * @param  list<array{field: string, old_value: mixed, new_value: mixed}>  $changes
     */
    private function createRevisionSnapshot(
        Requirement $requirement,
        int $version,
        User $actor,
        array $changes,
        string $summary,
    ): void {
        try {
            RequirementVersion::query()->create([
                'requirement_id' => $requirement->id,
                'version_number' => $version,
                'changed_by_id' => $actor->id,
                'changed_at' => now(),
                'changes' => $changes,
                'change_summary' => $summary,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(
                'REQUIREMENT_VERSION_CONFLICT',
                message: 'The requirement was revised concurrently. Reload and try again.',
            );
        }
    }

    private function audit(
        User $actor,
        Requirement $requirement,
        int $actionType,
        ?array $detail = null,
    ): void {
        AuditLogger::log($actor->id, [
            'user_name' => $actor->username,
            'user_display_name' => $actor->display_name,
            'user_type' => $actor->user_type,
            'module' => 2,
            'action_type' => $actionType,
            'target_type' => 'requirement',
            'target_id' => $requirement->id,
            'target_name' => $requirement->title,
            'detail' => $detail,
        ]);
    }

    private function freshRequirement(Requirement $requirement): Requirement
    {
        return $requirement->fresh([
            'submitter:id,display_name',
            'reviewer:id,display_name',
            'projects:id,name',
            'projectLinks',
        ]);
    }

    private function deliveryStatusFrom(int $status): ProjectDeliveryStatus
    {
        $deliveryStatus = ProjectDeliveryStatus::tryFrom($status);

        if ($deliveryStatus === null) {
            throw ValidationException::withMessages([
                'status' => 'The delivery status is invalid.',
            ]);
        }

        return $deliveryStatus;
    }
}
