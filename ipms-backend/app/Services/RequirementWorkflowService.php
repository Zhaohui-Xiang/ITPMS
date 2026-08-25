<?php

namespace App\Services;

use App\Enums\ProjectDeliveryStatus;
use App\Enums\RequirementStatus;
use App\Models\ProjectVersionHistory;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\RequirementVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequirementWorkflowService
{
    private const EDITABLE_FIELDS = [
        'title',
        'description',
        'priority',
        'requirement_type',
        'expected_completion_date',
    ];

    public function submit(array $data, User $actor): Requirement
    {
        return DB::transaction(function () use ($data, $actor): Requirement {
            $projectIds = $this->normalizeProjectIds($data['project_ids'] ?? []);
            $attributes = Arr::only($data, self::EDITABLE_FIELDS);

            $requirement = Requirement::query()->create([
                ...$attributes,
                'submitter_id' => $actor->id,
                'submitted_at' => now(),
                'status' => RequirementStatus::PENDING_REVIEW->value,
                'version' => 1,
                'created_by_id' => $actor->id,
            ]);

            foreach ($projectIds as $projectId) {
                RequirementProject::query()->create([
                    'requirement_id' => $requirement->id,
                    'project_id' => $projectId,
                    'delivery_status' => ProjectDeliveryStatus::ASSIGNED,
                ]);
            }

            return $requirement->fresh([
                'submitter:id,display_name',
                'projects:id,name',
                'projectLinks',
            ]);
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

        return DB::transaction(function () use (
            $requirement,
            $reviewer,
            $action,
            $comment,
        ): Requirement {
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
                $this->initializeApprovedProjects($locked, $reviewer);
            }

            return $locked->fresh(['projectLinks']);
        });
    }

    public function resubmit(
        Requirement $requirement,
        User $requester,
        array $data,
    ): Requirement {
        return DB::transaction(function () use ($requirement, $requester, $data): Requirement {
            $locked = $this->lockRequirement($requirement);

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

            $projectIds = array_key_exists('project_ids', $data)
                ? $this->normalizeProjectIds($data['project_ids'])
                : $locked->projectLinks()->orderBy('project_id')->pluck('project_id')->all();

            $changes = $this->revisionChanges($locked, $data, $projectIds);
            $newVersion = $locked->version + 1;
            $attributes = Arr::only($data, self::EDITABLE_FIELDS);

            $locked->update([
                ...$attributes,
                'status' => RequirementStatus::PENDING_REVIEW->value,
                'reviewer_id' => null,
                'review_comment' => null,
                'reviewed_at' => null,
                'submitted_at' => now(),
                'version' => $newVersion,
                'updated_by_id' => $requester->id,
            ]);

            $this->synchronizeProjectLinks($locked, $projectIds);

            RequirementVersion::query()->create([
                'requirement_id' => $locked->id,
                'version_number' => $newVersion,
                'changed_by_id' => $requester->id,
                'changed_at' => now(),
                'changes' => $changes,
                'change_summary' => 'Requirement resubmitted',
            ]);

            return $locked->fresh([
                'submitter:id,display_name',
                'reviewer:id,display_name',
                'projects:id,name',
                'projectLinks',
            ]);
        });
    }

    public function initializeApprovedProjects(
        Requirement $requirement,
        User $actor,
    ): RequirementStatus {
        return DB::transaction(function () use ($requirement, $actor): RequirementStatus {
            $locked = $this->lockRequirement($requirement);
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
                $link->update([
                    'delivery_status' => ProjectDeliveryStatus::ASSIGNED,
                ]);
            }

            $locked->update([
                'status' => RequirementStatus::ASSIGNED->value,
                'updated_by_id' => $actor->id,
            ]);

            return RequirementStatus::ASSIGNED;
        });
    }

    public function transitionProjectDelivery(
        Requirement $requirement,
        int $projectId,
        ProjectDeliveryStatus|int $targetStatus,
        User $actor,
    ): RequirementProject {
        return DB::transaction(function () use (
            $requirement,
            $projectId,
            $targetStatus,
            $actor,
        ): RequirementProject {
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

            $this->recalculateAggregateStatus($lockedRequirement);

            return $link->fresh();
        });
    }

    public function recalculateAggregateStatus(Requirement $requirement): RequirementStatus
    {
        $requirement->refresh();

        if ($requirement->status === RequirementStatus::PENDING_REVIEW->value) {
            return RequirementStatus::PENDING_REVIEW;
        }

        $minimum = $requirement->projectLinks()->min('delivery_status');

        if ($minimum === null) {
            return RequirementStatus::from($requirement->status);
        }

        $status = RequirementStatus::from((int) $minimum);

        if ($requirement->status !== $status->value) {
            $requirement->update(['status' => $status->value]);
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
     * @param  list<int>  $projectIds
     * @return list<array{field: string, old_value: mixed, new_value: mixed}>
     */
    private function revisionChanges(
        Requirement $requirement,
        array $data,
        array $projectIds,
    ): array {
        $changes = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $oldValue = $requirement->getAttribute($field);
            $newValue = $data[$field];

            if ($oldValue != $newValue) {
                $changes[] = [
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                ];
            }
        }

        if (array_key_exists('project_ids', $data)) {
            $oldProjectIds = $requirement->projectLinks()
                ->orderBy('project_id')
                ->pluck('project_id')
                ->map(static fn (mixed $projectId): int => (int) $projectId)
                ->all();
            $newProjectIds = $projectIds;
            sort($newProjectIds);

            if ($oldProjectIds !== $newProjectIds) {
                $changes[] = [
                    'field' => 'project_ids',
                    'old_value' => $oldProjectIds,
                    'new_value' => $newProjectIds,
                ];
            }
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

            $link->update([
                'delivery_status' => ProjectDeliveryStatus::ASSIGNED,
            ]);
        }

        foreach ($projectIds as $projectId) {
            if ($links->has($projectId)) {
                continue;
            }

            RequirementProject::query()->create([
                'requirement_id' => $requirement->id,
                'project_id' => $projectId,
                'delivery_status' => ProjectDeliveryStatus::ASSIGNED,
            ]);
        }
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
