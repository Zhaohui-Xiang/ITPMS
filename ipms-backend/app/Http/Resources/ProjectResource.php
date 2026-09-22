<?php

namespace App\Http\Resources;

use App\Enums\ProjectStatus;
use App\Enums\ProjectVersionStatus;
use App\Models\ProjectVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = ProjectStatus::tryFrom((int) $this->status);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'system_type' => $this->system_type,
            'description' => $this->description,
            'status' => (int) $this->status,
            'status_code' => $status?->name,
            'status_label' => $status?->label(),
            'manager' => $this->summary($this->manager),
            'supplier_org' => $this->organizationSummary($this->supplierOrg),
            'counts' => [
                'requirements' => (int) ($this->requirements_count ?? 0),
                'tasks' => (int) ($this->tasks_count ?? 0),
                'defects' => (int) ($this->defects_count ?? 0),
                'versions' => (int) ($this->versions_count ?? 0),
            ],
            'version_counts' => [
                'total' => (int) ($this->versions_count ?? 0),
                'by_status' => collect(ProjectVersionStatus::cases())
                    ->mapWithKeys(fn (ProjectVersionStatus $versionStatus): array => [
                        $versionStatus->name => (int) (
                            $this->{strtolower($versionStatus->name).'_versions_count'} ?? 0
                        ),
                    ])
                    ->all(),
            ],
            'allowed_actions' => $this->allowedActions($request),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function allowedActions(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        $actions = [];
        if (Gate::forUser($user)->allows('manageMembers', $this->resource)) {
            $actions[] = 'manage_members';
        }
        if (Gate::forUser($user)->allows('update', $this->resource)) {
            $actions[] = 'edit';
        }
        if (Gate::forUser($user)->allows('archive', $this->resource)) {
            $actions[] = 'archive';
        }
        if (Gate::forUser($user)->allows('delete', $this->resource)) {
            $actions[] = 'delete';
        }
        if (Gate::forUser($user)->allows('create', [ProjectVersion::class, $this->resource])) {
            $actions[] = 'create_version';
        }

        return $actions;
    }

    private function summary(mixed $user): ?array
    {
        return $user === null ? null : [
            'id' => $user->id,
            'display_name' => $user->display_name,
        ];
    }

    private function organizationSummary(mixed $organization): ?array
    {
        return $organization === null ? null : [
            'id' => $organization->id,
            'name' => $organization->name,
        ];
    }
}
