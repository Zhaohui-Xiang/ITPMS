<?php

namespace App\Http\Resources;

use App\Enums\ProjectVersionStatus;
use App\Models\ProjectVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectVersion
 */
final class ProjectVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status;
        $project = $this->relationLoaded('project') ? $this->project : null;
        $owner = $this->relationLoaded('owner') ? $this->owner : null;
        $scope = $this->relationLoaded('requirementLinks')
            ? $this->requirementLinks
            : collect();
        $history = $this->relationLoaded('histories')
            ? $this->histories
            : collect();
        $snapshot = $this->relationLoaded('releaseSnapshot')
            ? $this->releaseSnapshot
            : null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $status->value,
            'status_code' => $status->name,
            'status_label' => $status->label(),
            'project' => $project === null ? null : [
                'id' => $project->id,
                'name' => $project->name,
                'system_type' => $project->system_type,
            ],
            'owner' => $owner === null ? null : [
                'id' => $owner->id,
                'display_name' => $owner->display_name,
            ],
            'planned_start_date' => $this->planned_start_date?->toDateString(),
            'planned_release_date' => $this->planned_release_date?->toDateString(),
            'released_at' => $this->released_at?->toISOString(),
            'release_notes' => $this->release_notes,
            'lock_version' => $this->lock_version,
            'counts' => [
                'requirements' => (int) ($this->requirement_links_count ?? $scope->count()),
                'histories' => (int) ($this->histories_count ?? $history->count()),
                'tasks' => (int) ($this->scope_task_count ?? 0),
                'defects' => (int) ($this->scope_defect_count ?? 0),
            ],
            'gate_result' => $this->gate_result,
            'scope' => $scope->map(static function ($link): array {
                $requirement = $link->relationLoaded('requirement')
                    ? $link->requirement
                    : null;

                return [
                    'requirement_project_id' => $link->id,
                    'requirement_id' => $link->requirement_id,
                    'project_id' => $link->project_id,
                    'project_version_id' => $link->project_version_id,
                    'delivery_status' => $link->delivery_status->value,
                    'version_assigned_at' => $link->version_assigned_at?->toISOString(),
                    'requirement' => $requirement === null ? null : [
                        'id' => $requirement->id,
                        'title' => $requirement->title,
                        'status' => (int) $requirement->status,
                        'priority' => (int) $requirement->priority,
                    ],
                ];
            })->values()->all(),
            'history' => $history->map(static function ($item): array {
                $actor = $item->relationLoaded('actor') ? $item->actor : null;

                return [
                    'id' => $item->id,
                    'event_type' => $item->event_type,
                    'from_status' => $item->from_status?->value,
                    'from_status_code' => $item->from_status?->name,
                    'to_status' => $item->to_status?->value,
                    'to_status_code' => $item->to_status?->name,
                    'reason' => $item->reason,
                    'metadata' => $item->metadata,
                    'actor' => $actor === null ? null : [
                        'id' => $actor->id,
                        'display_name' => $actor->display_name,
                    ],
                    'created_at' => $item->created_at?->toISOString(),
                ];
            })->values()->all(),
            'release_snapshot' => $snapshot === null ? null : [
                'id' => $snapshot->id,
                'requirement_scope' => $snapshot->requirement_scope,
                'task_count' => $snapshot->task_count,
                'defect_count' => $snapshot->defect_count,
                'gate_result' => $snapshot->gate_result,
                'release_notes' => $snapshot->release_notes,
                'is_override' => $snapshot->is_override,
                'override_reason' => $snapshot->override_reason,
                'released_by' => $snapshot->releasedBy === null ? null : [
                    'id' => $snapshot->releasedBy->id,
                    'display_name' => $snapshot->releasedBy->display_name,
                ],
                'released_at' => $snapshot->released_at?->toISOString(),
            ],
            'allowed_actions' => $this->allowedActions($request, $status),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedActions(
        Request $request,
        ProjectVersionStatus $status,
    ): array {
        $user = $request->user();
        if ($user === null) {
            return [];
        }
        $abilityMatrix = $this->resource->getAttribute('ability_matrix');
        $allows = fn (string $ability): bool => (
            is_array($abilityMatrix) && array_key_exists($ability, $abilityMatrix)
                ? (bool) $abilityMatrix[$ability]
                : $user->can($ability, $this->resource)
        );

        $actions = [];
        $scopeMutable = ! in_array($status, [
            ProjectVersionStatus::READY_TO_RELEASE,
            ProjectVersionStatus::RELEASED,
            ProjectVersionStatus::ARCHIVED,
        ], true);

        if ($scopeMutable && $allows('update')) {
            $actions[] = 'edit';
        }
        if ($allows('delete')) {
            $actions[] = 'delete';
        }

        $hasNonReleaseTransition = collect($status->allowedTransitions())
            ->contains(static fn (ProjectVersionStatus $target): bool => (
                $target !== ProjectVersionStatus::RELEASED
            ));
        if ($hasNonReleaseTransition && $allows('transition')) {
            $actions[] = 'transition';
        }
        if ($scopeMutable && $allows('update')) {
            $actions[] = 'plan_requirements';
        }
        if ($status === ProjectVersionStatus::READY_TO_RELEASE
            && $allows('release')) {
            $actions[] = 'release';
        }
        if (in_array($status, [
            ProjectVersionStatus::IN_TESTING,
            ProjectVersionStatus::READY_TO_RELEASE,
        ], true) && $allows('forceRelease')) {
            $actions[] = 'force_release';
        }

        return $actions;
    }
}
