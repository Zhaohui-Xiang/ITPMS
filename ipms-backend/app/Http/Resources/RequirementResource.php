<?php

namespace App\Http\Resources;

use App\Enums\Priority;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\RequirementStatus;
use App\Models\RequirementProject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class RequirementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = RequirementStatus::tryFrom((int) $this->status);
        $priority = Priority::tryFrom((int) $this->priority);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => (int) $this->priority,
            'priority_code' => $priority?->name,
            'priority_label' => $priority?->label(),
            'requirement_type' => $this->requirement_type,
            'status' => (int) $this->status,
            'status_code' => $status?->name,
            'status_label' => $status?->label(),
            'submitter_id' => $this->submitter_id,
            'reviewer_id' => $this->reviewer_id,
            'dev_lead_id' => $this->dev_lead_id,
            'submitter' => $this->userSummary($this->submitter),
            'reviewer' => $this->userSummary($this->reviewer),
            'dev_lead' => $this->userSummary($this->devLead),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'review_comment' => $this->review_comment,
            'expected_completion_date' => $this->expected_completion_date?->toDateString(),
            'version' => (int) $this->version,
            'projects' => $this->projects->map(fn ($project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'system_type' => $project->system_type,
            ])->values()->all(),
            'project_deliveries' => $this->projectLinks
                ->map(fn (RequirementProject $link): array => $this->delivery($link))
                ->values()
                ->all(),
            'allowed_actions' => $this->allowedActions($request),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function delivery(RequirementProject $link): array
    {
        $deliveryStatus = $link->delivery_status instanceof ProjectDeliveryStatus
            ? $link->delivery_status
            : ProjectDeliveryStatus::tryFrom((int) $link->delivery_status);
        $version = $link->projectVersion;
        $owner = $version?->owner ?? $link->project?->manager;

        return [
            'project' => $link->project === null ? null : [
                'id' => $link->project->id,
                'name' => $link->project->name,
                'system_type' => $link->project->system_type,
            ],
            'delivery_status' => $deliveryStatus?->value,
            'delivery_status_code' => $deliveryStatus?->name,
            'delivery_status_label' => $deliveryStatus?->label(),
            'target_version' => $version === null ? null : [
                'id' => $version->id,
                'code' => $version->code,
                'name' => $version->name,
                'status' => $version->status->value,
                'status_code' => $version->status->name,
                'status_label' => $version->status->label(),
            ],
            'owner' => $this->userSummary($owner),
            'task_progress' => [
                'total' => (int) ($link->task_total ?? 0),
                'completed' => (int) ($link->task_completed ?? 0),
            ],
            'open_severe_defect_count' => (int) ($link->open_severe_defect_count ?? 0),
        ];
    }

    private function allowedActions(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        $isPendingReview = $this->status === RequirementStatus::PENDING_REVIEW->value;
        $isRejected = $this->resource->isRejectedForResubmission();

        $actions = [];
        if (Gate::forUser($user)->allows('update', $this->resource)) {
            $actions[] = 'edit';
        }
        if ($isPendingReview
            && ! $isRejected
            && Gate::forUser($user)->allows('approve', $this->resource)) {
            $actions[] = 'review';
        }
        if (! $isPendingReview && $this->projectLinks->contains(
            fn (RequirementProject $link): bool => Gate::forUser($user)->allows(
                'transitionProject',
                [$this->resource, $link->project],
            ),
        )) {
            $actions[] = 'transition_project';
        }
        if (! $isPendingReview && $this->projectLinks->contains(
            fn (RequirementProject $link): bool => Gate::forUser($user)->allows(
                'createTask',
                [$this->resource, $link->project],
            ),
        )) {
            $actions[] = 'create_task';
        }

        return $actions;
    }

    private function userSummary(mixed $user): ?array
    {
        return $user === null ? null : [
            'id' => $user->id,
            'display_name' => $user->display_name,
        ];
    }
}
