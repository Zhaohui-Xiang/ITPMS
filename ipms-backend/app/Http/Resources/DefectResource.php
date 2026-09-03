<?php

namespace App\Http\Resources;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class DefectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = DefectStatus::tryFrom((int) $this->status);
        $severity = DefectSeverity::tryFrom((int) $this->severity);

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
            'reporter' => $this->userSummary($this->reporter),
            'assignee' => $this->userSummary($this->assignee),
            'severity' => (int) $this->severity,
            'severity_code' => $severity?->name,
            'severity_label' => $severity?->label(),
            'defect_type' => $this->defect_type,
            'discovered_at' => $this->discovered_at?->toISOString(),
            'discovery_phase' => $this->discovery_phase,
            'status' => (int) $this->status,
            'status_code' => $status?->name,
            'status_label' => $status?->label(),
            'screenshot' => $this->screenshot,
            'fix_description' => $this->fix_description,
            'closed_at' => $this->closed_at?->toISOString(),
            'allowed_actions' => $this->allowedActions($request, $status),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function allowedActions(Request $request, ?DefectStatus $status): array
    {
        $user = $request->user();
        if ($user === null || $status === null) {
            return [];
        }

        $actions = [];
        if (Gate::forUser($user)->allows('update', $this->resource)) {
            $actions[] = 'edit';
        }
        if ($status->canConfirm()
            && Gate::forUser($user)->allows('confirm', $this->resource)) {
            $actions[] = 'confirm';
        }
        if ($status->canAssign()
            && Gate::forUser($user)->allows('assign', $this->resource)) {
            $actions[] = 'assign';
        }
        if ($status->canResolve()
            && Gate::forUser($user)->allows('resolve', $this->resource)) {
            $actions[] = 'resolve';
        }
        if ($status->canVerify()
            && Gate::forUser($user)->allows('verify', $this->resource)) {
            $actions[] = 'verify';
        }
        if ($status->canReopen()
            && Gate::forUser($user)->allows('reopen', $this->resource)) {
            $actions[] = 'reopen';
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
