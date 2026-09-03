<?php

namespace App\Http\Resources;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = TaskStatus::tryFrom((int) $this->status);
        $priority = Priority::tryFrom((int) $this->priority);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'requirement' => $this->requirement === null ? null : [
                'id' => $this->requirement->id,
                'title' => $this->requirement->title,
            ],
            'project' => $this->project === null ? null : [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ],
            'assignee' => $this->userSummary($this->assignee),
            'priority' => (int) $this->priority,
            'priority_code' => $priority?->name,
            'priority_label' => $priority?->label(),
            'status' => (int) $this->status,
            'status_code' => $status?->name,
            'status_label' => $status?->label(),
            'due_date' => $this->due_date?->toDateString(),
            'remind_days_before' => $this->remind_days_before,
            'estimated_hours' => $this->estimated_hours,
            'actual_hours' => $this->actual_hours,
            'suspend_reason' => $this->suspend_reason,
            'completed_at' => $this->completed_at?->toISOString(),
            'allowed_actions' => $this->allowedActions($request, $status),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function allowedActions(Request $request, ?TaskStatus $status): array
    {
        $user = $request->user();
        if ($user === null || $status === null) {
            return [];
        }

        $actions = [];
        if (Gate::forUser($user)->allows('update', $this->resource)) {
            $actions[] = 'edit';
        }
        if ($status === TaskStatus::TODO
            && $this->assignee_id === null
            && Gate::forUser($user)->allows('claim', $this->resource)) {
            $actions[] = 'claim';
        }
        if ($status->allowedTransitions() !== []
            && Gate::forUser($user)->allows('transition', $this->resource)) {
            $actions[] = 'transition';
        }
        if (in_array(TaskStatus::SUSPENDED, $status->allowedTransitions(), true)
            && Gate::forUser($user)->allows('hold', $this->resource)) {
            $actions[] = 'hold';
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
