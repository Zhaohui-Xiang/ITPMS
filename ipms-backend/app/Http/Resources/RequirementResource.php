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
        $attachments = $this->relationLoaded('attachments')
            ? $this->attachments
            : collect();

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
                ->map(fn (RequirementProject $link): array => $this->delivery(
                    $link,
                    $request,
                ))
                ->values()
                ->all(),
            'attachments' => $attachments->map(fn ($attachment): array => [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'file_size' => (int) $attachment->file_size,
                'file_type' => $attachment->file_type,
                'uploaded_by' => $this->userSummary($attachment->uploader),
                'uploaded_at' => $attachment->uploaded_at?->toISOString(),
            ])->values()->all(),
            'allowed_actions' => $this->allowedActions($request),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function delivery(
        RequirementProject $link,
        Request $request,
    ): array {
        $deliveryStatus = $link->delivery_status instanceof ProjectDeliveryStatus
            ? $link->delivery_status
            : ProjectDeliveryStatus::tryFrom((int) $link->delivery_status);
        $project = $link->project;
        $version = $link->projectVersion;
        $owner = $version?->owner ?? $project?->manager;
        $user = $request->user();
        $canViewProject = $user !== null
            && $project !== null
            && Gate::forUser($user)->allows('view', $project);
        $canTransition = $user !== null
            && $project !== null
            && Gate::forUser($user)->allows(
                'transitionProject',
                [$this->resource, $project],
            );

        return [
            'project' => $project === null ? null : [
                'id' => $project->id,
                'name' => $project->name,
                'system_type' => $project->system_type,
            ],
            'can_view_project' => $canViewProject,
            'allowed_actions' => $canTransition ? ['transition'] : [],
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
