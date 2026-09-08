<?php

namespace App\Services;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\RequirementStatus;
use App\Enums\TaskStatus;
use App\Models\Defect;
use App\Models\ProjectVersion;
use App\Models\RequirementProject;
use App\Models\Task;
use App\ValueObjects\ReleaseGateResult;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

final class ReleaseGateService
{
    private const READY_TARGETS = [
        ProjectVersionStatus::READY_TO_RELEASE,
        ProjectVersionStatus::RELEASED,
    ];

    public function check(
        ProjectVersion $version,
        ProjectVersionStatus $target,
    ): ReleaseGateResult {
        $version = ProjectVersion::query()->findOrFail($version->id);
        $links = RequirementProject::query()
            ->with('requirement:id,status,dev_lead_id')
            ->where('project_version_id', $version->id)
            ->where('project_id', $version->project_id)
            ->orderBy('id')
            ->get();

        return $this->evaluate($version, $target, $links);
    }

    /**
     * Evaluate many versions with a bounded set of database queries.
     *
     * @param  Collection<int, ProjectVersion>  $versions
     * @param  callable(ProjectVersion): ProjectVersionStatus  $targetResolver
     * @return Collection<int, ReleaseGateResult>
     */
    public function checkMany(
        Collection $versions,
        callable $targetResolver,
    ): Collection {
        $versionIds = $versions
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($versionIds->isEmpty()) {
            return collect();
        }

        $freshVersions = ProjectVersion::query()
            ->whereKey($versionIds)
            ->get()
            ->keyBy(static fn (ProjectVersion $version): int => (int) $version->id);
        $links = RequirementProject::query()
            ->with('requirement:id,status,dev_lead_id')
            ->whereIn('project_version_id', $versionIds)
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (RequirementProject $link): int => (int) $link->project_version_id);
        $allLinks = $links->flatten(1);
        $tasksByScope = $allLinks->isEmpty()
            ? collect()
            : Task::query()
                ->whereExists(function (QueryBuilder $scopeQuery) use ($versionIds): void {
                    $scopeQuery
                        ->selectRaw('1')
                        ->from('requirement_project as gate_scopes')
                        ->whereColumn('gate_scopes.project_id', 'tasks.project_id')
                        ->whereColumn('gate_scopes.requirement_id', 'tasks.requirement_id')
                        ->whereIn('gate_scopes.project_version_id', $versionIds);
                })
                ->orderBy('tasks.id')
                ->get()
                ->groupBy(
                    static fn (Task $task): string => "{$task->project_id}:{$task->requirement_id}",
                );
        $defectsByScope = $allLinks->isEmpty()
            ? collect()
            : Defect::query()
                ->whereExists(function (QueryBuilder $scopeQuery) use ($versionIds): void {
                    $scopeQuery
                        ->selectRaw('1')
                        ->from('requirement_project as gate_scopes')
                        ->whereColumn('gate_scopes.project_id', 'defects.project_id')
                        ->whereColumn('gate_scopes.requirement_id', 'defects.requirement_id')
                        ->whereIn('gate_scopes.project_version_id', $versionIds);
                })
                ->orderBy('defects.id')
                ->get()
                ->groupBy(
                    static fn (Defect $defect): string => "{$defect->project_id}:{$defect->requirement_id}",
                );

        return $freshVersions->mapWithKeys(function (ProjectVersion $version) use (
            $targetResolver,
            $links,
            $tasksByScope,
            $defectsByScope,
        ): array {
            $versionLinks = $links
                ->get($version->id, collect())
                ->where('project_id', $version->project_id)
                ->values();
            $scopeKeys = $versionLinks
                ->map(
                    static fn (RequirementProject $link): string => "{$link->project_id}:{$link->requirement_id}",
                )
                ->unique();
            $versionTasks = $scopeKeys
                ->flatMap(
                    static fn (string $key): Collection => $tasksByScope->get($key, collect()),
                )
                ->values();
            $versionDefects = $scopeKeys
                ->flatMap(
                    static fn (string $key): Collection => $defectsByScope->get($key, collect()),
                )
                ->values();

            return [
                (int) $version->id => $this->evaluate(
                    $version,
                    $targetResolver($version),
                    $versionLinks,
                    $versionTasks,
                    $versionDefects,
                ),
            ];
        });
    }

    /**
     * @param  Collection<int, RequirementProject>  $links
     * @param  Collection<int, Task>|null  $tasks
     * @param  Collection<int, Defect>|null  $defects
     */
    private function evaluate(
        ProjectVersion $version,
        ProjectVersionStatus $target,
        Collection $links,
        ?Collection $tasks = null,
        ?Collection $defects = null,
    ): ReleaseGateResult {
        return new ReleaseGateResult([
            $this->metadataCheck($version, $target),
            $this->scopeCheck($links, $target),
            $this->reviewedAssignedCheck($links, $target),
            $this->deliveryCheck($links, $target),
            $this->tasksCheck($version, $links, $target, $tasks),
            $this->defectsCheck($version, $links, $target, $defects),
            $this->notesCheck($version, $target),
            $this->acceptanceCheck($links, $target),
        ]);
    }

    /**
     * @return array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}
     */
    private function metadataCheck(
        ProjectVersion $version,
        ProjectVersionStatus $target,
    ): array {
        $applicable = $target === ProjectVersionStatus::PLANNED;
        $missing = [];

        if ($applicable && $version->owner_id === null) {
            $missing[] = 'owner_id';
        }
        if ($applicable && $version->planned_release_date === null) {
            $missing[] = 'planned_release_date';
        }

        return $this->result(
            'version_metadata',
            'Version owner and planned release date',
            $applicable,
            $missing === [],
            [
                'applicable' => $applicable,
                'missing' => $missing,
            ],
        );
    }

    /**
     * @param  Collection<int, RequirementProject>  $links
     * @return array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}
     */
    private function scopeCheck(Collection $links, ProjectVersionStatus $target): array
    {
        $applicable = $target === ProjectVersionStatus::IN_DEVELOPMENT;

        return $this->result(
            'non_empty_scope',
            'Version scope is not empty',
            $applicable,
            ! $applicable || $links->isNotEmpty(),
            [
                'applicable' => $applicable,
                'requirement_project_count' => $links->count(),
                'requirement_project_ids' => $links->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ],
        );
    }

    /**
     * @param  Collection<int, RequirementProject>  $links
     * @return array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}
     */
    private function reviewedAssignedCheck(
        Collection $links,
        ProjectVersionStatus $target,
    ): array {
        $applicable = $target === ProjectVersionStatus::IN_DEVELOPMENT;
        $unapproved = $applicable
            ? $links->filter(
                fn (RequirementProject $link): bool => $link->requirement->status < RequirementStatus::ASSIGNED->value,
            )->pluck('id')->map(fn ($id): int => (int) $id)->values()->all()
            : [];
        $missingOwner = $applicable
            ? $links->filter(
                fn (RequirementProject $link): bool => $link->requirement->dev_lead_id === null,
            )->pluck('id')->map(fn ($id): int => (int) $id)->values()->all()
            : [];

        return $this->result(
            'reviewed_assigned_scope',
            'Requirements are approved and have execution owners',
            $applicable,
            $unapproved === [] && $missingOwner === [],
            [
                'applicable' => $applicable,
                'unapproved_requirement_project_ids' => $unapproved,
                'missing_execution_owner_requirement_project_ids' => $missingOwner,
            ],
        );
    }

    /**
     * @param  Collection<int, RequirementProject>  $links
     * @return array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}
     */
    private function deliveryCheck(
        Collection $links,
        ProjectVersionStatus $target,
    ): array {
        $ready = in_array($target, self::READY_TARGETS, true);
        $testing = $target === ProjectVersionStatus::IN_TESTING;
        $applicable = $testing || $ready;
        $minimum = $testing ? ProjectDeliveryStatus::IN_TESTING->value : null;
        $required = $ready ? ProjectDeliveryStatus::PENDING_DEPLOY->value : null;

        $failing = $applicable
            ? $links->filter(function (RequirementProject $link) use ($testing, $required): bool {
                $status = $link->delivery_status->value;

                return $testing
                    ? $status < ProjectDeliveryStatus::IN_TESTING->value
                    : $status !== $required;
            })->values()
            : collect();

        return $this->result(
            'project_delivery',
            $ready
                ? 'Project delivery is pending deploy'
                : 'Project delivery reached testing',
            $applicable,
            $failing->isEmpty(),
            [
                'applicable' => $applicable,
                'minimum_status' => $minimum,
                'required_status' => $required,
                'failing_requirement_project_ids' => $failing
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
                'failing' => $failing->map(fn (RequirementProject $link): array => [
                    'requirement_project_id' => $link->id,
                    'delivery_status' => $link->delivery_status->value,
                ])->all(),
            ],
        );
    }

    /**
     * @param  Collection<int, RequirementProject>  $links
     * @return array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}
     */
    private function tasksCheck(
        ProjectVersion $version,
        Collection $links,
        ProjectVersionStatus $target,
        ?Collection $scopedTasks = null,
    ): array {
        $applicable = in_array($target, self::READY_TARGETS, true);
        $tasks = $applicable
            ? ($scopedTasks ?? $this->scopeTasks($version, $links))
            : collect();
        $incomplete = $tasks->filter(
            fn (Task $task): bool => $task->status !== TaskStatus::COMPLETED->value,
        );

        return $this->result(
            'tasks_completed',
            'All scope tasks are completed',
            $applicable,
            $incomplete->isEmpty(),
            [
                'applicable' => $applicable,
                'total_count' => $tasks->count(),
                'incomplete_count' => $incomplete->count(),
                'incomplete_task_ids' => $incomplete
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
            ],
        );
    }

    /**
     * @param  Collection<int, RequirementProject>  $links
     * @return array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}
     */
    private function defectsCheck(
        ProjectVersion $version,
        Collection $links,
        ProjectVersionStatus $target,
        ?Collection $scopedDefects = null,
    ): array {
        $ready = in_array($target, self::READY_TARGETS, true);
        $archive = $target === ProjectVersionStatus::ARCHIVED;
        $applicable = $ready || $archive;
        $mode = $archive ? 'all' : 'severe';
        $defects = $applicable
            ? ($scopedDefects ?? $this->scopeDefects($version, $links))
            : collect();
        $relevant = $archive
            ? $defects
            : $defects->whereIn('severity', [
                DefectSeverity::FATAL->value,
                DefectSeverity::SERIOUS->value,
            ]);
        $open = $relevant->filter(
            fn (Defect $defect): bool => $defect->status !== DefectStatus::CLOSED->value,
        );

        return $this->result(
            'severe_defects_closed',
            $archive
                ? 'All scope defects are closed'
                : 'Fatal and serious scope defects are closed',
            $applicable,
            $open->isEmpty(),
            [
                'applicable' => $applicable,
                'mode' => $mode,
                'relevant_count' => $relevant->count(),
                'open_count' => $open->count(),
                'open_defect_ids' => $open
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
            ],
        );
    }

    /**
     * @return array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}
     */
    private function notesCheck(
        ProjectVersion $version,
        ProjectVersionStatus $target,
    ): array {
        $applicable = in_array($target, self::READY_TARGETS, true);
        $present = filled(trim((string) $version->release_notes));

        return $this->result(
            'release_notes_present',
            'Release notes are present',
            $applicable,
            ! $applicable || $present,
            [
                'applicable' => $applicable,
                'present' => $present,
            ],
        );
    }

    /**
     * @param  Collection<int, RequirementProject>  $links
     * @return array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}
     */
    private function acceptanceCheck(
        Collection $links,
        ProjectVersionStatus $target,
    ): array {
        $applicable = $target === ProjectVersionStatus::ARCHIVED;
        $failing = $applicable
            ? $links->filter(
                fn (RequirementProject $link): bool => $link->delivery_status !== ProjectDeliveryStatus::ACCEPTED,
            )
            : collect();

        return $this->result(
            'acceptance_complete',
            'All project delivery is accepted',
            $applicable,
            $failing->isEmpty(),
            [
                'applicable' => $applicable,
                'failing_requirement_project_ids' => $failing
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
            ],
        );
    }

    /**
     * @param  Collection<int, RequirementProject>  $links
     * @return Collection<int, Task>
     */
    private function scopeTasks(ProjectVersion $version, Collection $links): Collection
    {
        if ($links->isEmpty()) {
            return collect();
        }

        return Task::query()
            ->where('project_id', $version->project_id)
            ->whereIn('requirement_id', $links->pluck('requirement_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, RequirementProject>  $links
     * @return Collection<int, Defect>
     */
    private function scopeDefects(ProjectVersion $version, Collection $links): Collection
    {
        if ($links->isEmpty()) {
            return collect();
        }

        return Defect::query()
            ->where('project_id', $version->project_id)
            ->whereIn('requirement_id', $links->pluck('requirement_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{code: string, label: string, passed: bool, blocking: bool, details: array<string, mixed>}
     */
    private function result(
        string $code,
        string $label,
        bool $blocking,
        bool $passed,
        array $details,
    ): array {
        return [
            'code' => $code,
            'label' => $label,
            'passed' => $passed,
            'blocking' => $blocking,
            'details' => $details,
        ];
    }
}
