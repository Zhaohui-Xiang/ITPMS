<?php

namespace App\Services;

use App\Enums\ProjectDeliveryStatus;
use App\Enums\RequirementStatus;
use App\Exceptions\DomainConflictException;
use App\Http\Middleware\AuditLogger;
use App\Models\ProjectVersionHistory;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\RequirementVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RequirementWorkflowService
{
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
    ): Requirement {
        return DB::transaction(function () use ($requirement, $actor, $data): Requirement {
            $locked = $this->lockRequirement($requirement);

            if ($locked->isRejectedForResubmission()) {
                return $this->resubmitLocked($locked, $actor, $data);
            }

            $projectIds = null;
            $oldProjectIds = null;

            if (array_key_exists('project_ids', $data)) {
                if ($locked->status !== RequirementStatus::PENDING_REVIEW->value) {
                    throw new DomainConflictException(
                        'REQUIREMENT_PROJECT_SCOPE_LOCKED',
                        'Project links cannot be changed after requirement approval.',
                    );
                }

                if ($locked->submitter_id !== $actor->id) {
                    throw new AuthorizationException(
                        'Only the original requester may change linked projects.',
                    );
                }

                $projectIds = $this->normalizeProjectIds($data['project_ids']);
                $oldProjectIds = $this->lockedProjectIds($locked);
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
                    $this->synchronizeProjectLinks($locked, $projectIds);
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

            return $this->freshRequirement($locked);
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
            $locked = $this->lockRequirement($requirement);

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
                $this->initializeApprovedProjectsLocked($locked, $reviewer);
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
            return $this->resubmitLocked(
                $this->lockRequirement($requirement),
                $requester,
                $data,
            );
        });
    }

    public function initializeApprovedProjects(
        Requirement $requirement,
        User $actor,
    ): RequirementStatus {
        return DB::transaction(function () use ($requirement, $actor): RequirementStatus {
            return $this->initializeApprovedProjectsLocked(
                $this->lockRequirement($requirement),
                $actor,
            );
        });
    }

    public function transitionProjectDelivery(
        Requirement $requirement,
        int $projectId,
        ProjectDeliveryStatus|int $targetStatus,
        User $actor,
    ): RequirementProject {
        return DB::transaction(function () use ($requirement, $projectId, $targetStatus, $actor): RequirementProject {
            $lockedRequirement = $this->lockRequirement($requirement);

            if ($lockedRequirement->status === RequirementStatus::PENDING_REVIEW->value) {
                throw ValidationException::withMessages([
                    'status' => 'Project delivery cannot advance before approval.',
                ]);
            }

            $link = $lockedRequirement->projectLinks()
                ->where('project_id', $projectId)
                ->lockForUpdate()
                ->first();

            if ($link === null) {
                throw ValidationException::withMessages([
                    'project_id' => 'The project is not linked to this requirement.',
                ]);
            }

            Gate::forUser($actor)->authorize('transitionProject', [
                $lockedRequirement,
                $link->project()->firstOrFail(),
            ]);

            $target = $targetStatus instanceof ProjectDeliveryStatus
                ? $targetStatus
                : $this->deliveryStatusFrom($targetStatus);
            $current = $link->delivery_status;

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

            $this->recalculateAggregateStatusLocked($lockedRequirement);
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
            return $this->recalculateAggregateStatusLocked(
                $this->lockRequirement($requirement),
            );
        });
    }

    private function resubmitLocked(
        Requirement $locked,
        User $requester,
        array $data,
    ): Requirement {
        if ($locked->submitter_id !== $requester->id) {
            throw new AuthorizationException(
                'Only the original requester may resubmit this requirement.',
            );
        }

        if (! $locked->isRejectedForResubmission()) {
            throw ValidationException::withMessages([
                'requirement' => 'Only a rejected requirement may be resubmitted.',
            ]);
        }

        $oldProjectIds = $this->lockedProjectIds($locked);
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

        $this->synchronizeProjectLinks($locked, $projectIds);
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
    ): RequirementStatus {
        $links = $locked->projectLinks()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($links->isEmpty()) {
            throw ValidationException::withMessages([
                'project_ids' => 'An approved requirement must have at least one project.',
            ]);
        }

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
    ): RequirementStatus {
        if ($locked->status === RequirementStatus::PENDING_REVIEW->value) {
            return RequirementStatus::PENDING_REVIEW;
        }

        $links = $locked->projectLinks()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

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
    private function lockedProjectIds(Requirement $requirement): array
    {
        return $requirement->projectLinks()
            ->orderBy('project_id')
            ->lockForUpdate()
            ->pluck('project_id')
            ->map(static fn (mixed $projectId): int => (int) $projectId)
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
    ): void {
        $links = $requirement->projectLinks()
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('project_id');

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
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainConflictException(
                'REQUIREMENT_VERSION_CONFLICT',
                'The requirement was revised concurrently. Reload and try again.',
                $exception,
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
