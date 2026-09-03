<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectVersionStatus;
use App\Enums\UserType;
use App\Exceptions\DomainConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignRequirementVersionRequest;
use App\Http\Requests\ReleaseProjectVersionRequest;
use App\Http\Requests\StoreProjectVersionRequest;
use App\Http\Requests\TransitionProjectVersionRequest;
use App\Http\Requests\UpdateProjectVersionRequest;
use App\Http\Resources\ProjectVersionHistoryResource;
use App\Http\Resources\ProjectVersionResource;
use App\Http\Resources\ProjectVersionSummaryResource;
use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectReleaseService;
use App\Services\ProjectVersionService;
use App\Services\ReleaseGateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class ProjectVersionController extends Controller
{
    private const RECENT_HISTORY_LIMIT = 5;

    public function __construct(
        private readonly ProjectVersionService $versionService,
        private readonly ProjectReleaseService $releaseService,
        private readonly ReleaseGateService $releaseGateService,
    ) {}

    public function index(Request $request, int $projectId): JsonResponse
    {
        $project = Project::query()->findOrFail($projectId);
        $this->authorizeProjectAccess($request, $project);

        $validated = $request->validate([
            'status' => ['sometimes', 'integer', Rule::in($this->statusValues())],
            'owner_id' => ['sometimes', 'integer', 'exists:users,id'],
            'planned_release_from' => ['sometimes', 'date'],
            'planned_release_to' => ['sometimes', 'date', 'after_or_equal:planned_release_from'],
            'keyword' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $actor = $request->user();
        $isRequester = $actor->user_type === UserType::SYSTEM_USER->value;
        $query = ProjectVersion::query()
            ->select('project_versions.*')
            ->with([
                'project:id,name,system_type,manager_id',
                'owner:id,display_name',
            ]);
        $taskCountQuery = Task::query()
            ->selectRaw('COUNT(*)')
            ->whereColumn('tasks.project_id', 'project_versions.project_id')
            ->whereExists(static function ($scope): void {
                $scope
                    ->selectRaw('1')
                    ->from('requirement_project')
                    ->whereColumn(
                        'requirement_project.requirement_id',
                        'tasks.requirement_id',
                    )
                    ->whereColumn(
                        'requirement_project.project_version_id',
                        'project_versions.id',
                    );
            });
        $defectCountQuery = Defect::query()
            ->selectRaw('COUNT(*)')
            ->whereColumn('defects.project_id', 'project_versions.project_id')
            ->whereExists(static function ($scope): void {
                $scope
                    ->selectRaw('1')
                    ->from('requirement_project')
                    ->whereColumn(
                        'requirement_project.requirement_id',
                        'defects.requirement_id',
                    )
                    ->whereColumn(
                        'requirement_project.project_version_id',
                        'project_versions.id',
                    );
            });

        if ($isRequester) {
            $ownedRequirement = fn ($requirementQuery) => $requirementQuery
                ->where('submitter_id', $actor->id);
            $query
                ->whereHas('requirementLinks.requirement', $ownedRequirement)
                ->withCount([
                    'requirementLinks as requirement_links_count' => fn ($linkQuery) => $linkQuery
                        ->whereHas('requirement', $ownedRequirement),
                ])
                ->selectRaw('0 AS histories_count');
            $taskCountQuery->whereHas('requirement', $ownedRequirement);
            $defectCountQuery->whereHas('requirement', $ownedRequirement);
        } else {
            $query->withCount(['requirementLinks', 'histories']);
        }

        $query
            ->selectSub($taskCountQuery, 'scope_task_count')
            ->selectSub($defectCountQuery, 'scope_defect_count')
            ->where('project_id', $project->id);

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['owner_id'])) {
            $query->where('owner_id', $validated['owner_id']);
        }
        if (isset($validated['planned_release_from'])) {
            $query->whereDate('planned_release_date', '>=', $validated['planned_release_from']);
        }
        if (isset($validated['planned_release_to'])) {
            $query->whereDate('planned_release_date', '<=', $validated['planned_release_to']);
        }
        if (isset($validated['keyword'])) {
            $keyword = $validated['keyword'];
            $query->where(static function ($nested) use ($keyword): void {
                $nested
                    ->where('code', 'like', '%'.$keyword.'%')
                    ->orWhere('name', 'like', '%'.$keyword.'%');
            });
        }

        $pageSize = (int) ($validated['page_size'] ?? 20);
        $paginator = $query
            ->orderByDesc('planned_release_date')
            ->orderByDesc('id')
            ->paginate($pageSize);

        $abilityMatrix = $this->projectAbilityMatrix($request, $project);

        return ApiResponse::paginated(
            $paginator,
            fn (ProjectVersion $version): array => (
                new ProjectVersionSummaryResource(
                    $this->presentSummary($version, $abilityMatrix),
                )
            )->resolve($request),
        );
    }

    public function store(
        StoreProjectVersionRequest $request,
        int $projectId,
    ): JsonResponse {
        $project = Project::query()->findOrFail($projectId);
        Gate::forUser($request->user())->authorize(
            'create',
            [ProjectVersion::class, $project],
        );

        $version = $this->versionService->create(
            $project,
            $request->validated(),
            $request->user(),
        );

        return ApiResponse::success(
            new ProjectVersionResource($this->present($version, $request->user())),
            'Project version created.',
            201,
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $version = ProjectVersion::query()->findOrFail($id);
        $this->authorizeVersionAccess($request, $version);

        return ApiResponse::success(
            new ProjectVersionResource($this->present($version, $request->user())),
        );
    }

    public function update(
        UpdateProjectVersionRequest $request,
        int $id,
    ): JsonResponse {
        $version = ProjectVersion::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('update', $version);
        $data = $request->validated();

        $version = $this->versionService->update(
            $version,
            $data,
            (int) $data['lock_version'],
            $request->user(),
        );

        return ApiResponse::success(
            new ProjectVersionResource($this->present($version, $request->user())),
            'Project version updated.',
        );
    }

    public function destroy(
        UpdateProjectVersionRequest $request,
        int $id,
    ): JsonResponse {
        $version = ProjectVersion::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('delete', $version);
        $lockVersion = (int) $request->validated('lock_version');

        $this->versionService->deleteDraft(
            $version,
            $lockVersion,
            $request->user(),
        );

        return ApiResponse::success([
            'id' => $version->id,
            'lock_version' => $lockVersion,
            'deleted' => true,
        ], 'Project version deleted.');
    }

    public function transition(
        TransitionProjectVersionRequest $request,
        int $id,
    ): JsonResponse {
        $version = ProjectVersion::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('transition', $version);
        $data = $request->validated();

        $version = $this->versionService->transition(
            $version,
            ProjectVersionStatus::from((int) $data['status']),
            (int) $data['lock_version'],
            $request->user(),
            $data['reason'] ?? null,
        );

        return ApiResponse::success(
            new ProjectVersionResource($this->present($version, $request->user())),
            'Project version status updated.',
        );
    }

    public function gateCheck(Request $request, int $id): JsonResponse
    {
        $version = ProjectVersion::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('viewGate', $version);

        $validated = $request->validate([
            'target_status' => ['sometimes', 'integer', Rule::in($this->statusValues())],
        ]);
        $target = isset($validated['target_status'])
            ? ProjectVersionStatus::from((int) $validated['target_status'])
            : $this->defaultGateTarget($version->status);
        $result = $this->releaseGateService->check($version, $target);

        return ApiResponse::success([
            'target_status' => $target->value,
            'target_status_code' => $target->name,
            ...$result->jsonSerialize(),
        ]);
    }

    public function release(
        ReleaseProjectVersionRequest $request,
        int $id,
    ): JsonResponse {
        $version = ProjectVersion::query()->findOrFail($id);
        $data = $request->validated();
        $force = (bool) ($data['force'] ?? false);

        Gate::forUser($request->user())->authorize(
            $force ? 'forceRelease' : 'release',
            $version,
        );

        $version = $this->releaseService->release(
            $version,
            $request->user(),
            $data,
        );

        return ApiResponse::success(
            new ProjectVersionResource($this->present($version, $request->user())),
            $force ? 'Project version force released.' : 'Project version released.',
        );
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $version = ProjectVersion::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('viewHistory', $version);
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $paginator = $version->histories()
            ->with('actor:id,display_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['page_size'] ?? 20));

        return ApiResponse::paginated(
            $paginator,
            fn ($history): array => (
                new ProjectVersionHistoryResource($history)
            )->resolve($request),
        );
    }

    public function assignRequirement(
        AssignRequirementVersionRequest $request,
        int $requirementId,
        int $projectId,
    ): JsonResponse {
        $data = $request->validated();
        $link = $this->requirementProject($requirementId, $projectId);
        $version = ProjectVersion::query()->findOrFail(
            (int) $data['project_version_id'],
        );
        Gate::forUser($request->user())->authorize('update', $version);

        $version = $this->versionService->assignRequirement(
            $version,
            $link,
            (int) $data['lock_version'],
            $request->user(),
            $data['reason'] ?? null,
        );
        $link = $link->fresh();

        return ApiResponse::success([
            'requirement_project_id' => $link->id,
            'requirement_id' => $link->requirement_id,
            'project_id' => $link->project_id,
            'project_version_id' => $link->project_version_id,
            'lock_version' => $version->lock_version,
        ], 'Requirement planned into project version.');
    }

    public function unassignRequirement(
        AssignRequirementVersionRequest $request,
        int $requirementId,
        int $projectId,
    ): JsonResponse {
        $data = $request->validated();
        $link = $this->requirementProject($requirementId, $projectId);

        if ($link->project_version_id === null) {
            throw new DomainConflictException(
                'VERSION_PROJECT_MISMATCH',
                errors: ['project_version_id' => [
                    'expected' => 'assigned version',
                    'current' => null,
                ]],
                message: 'The requirement is not assigned to a project version.',
            );
        }

        $version = ProjectVersion::query()->findOrFail($link->project_version_id);
        Gate::forUser($request->user())->authorize('update', $version);

        $version = $this->versionService->unassignRequirement(
            $version,
            $link,
            (int) $data['lock_version'],
            $request->user(),
            $data['reason'] ?? null,
        );
        $link = $link->fresh();

        return ApiResponse::success([
            'requirement_project_id' => $link->id,
            'requirement_id' => $link->requirement_id,
            'project_id' => $link->project_id,
            'project_version_id' => $link->project_version_id,
            'lock_version' => $version->lock_version,
        ], 'Requirement returned to the unplanned pool.');
    }

    private function authorizeProjectAccess(Request $request, Project $project): void
    {
        Gate::forUser($request->user())->authorize(
            'viewProject',
            [ProjectVersion::class, $project],
        );
    }

    private function authorizeVersionAccess(
        Request $request,
        ProjectVersion $version,
    ): void {
        Gate::forUser($request->user())->authorize('view', $version);
    }

    private function requirementProject(
        int $requirementId,
        int $projectId,
    ): RequirementProject {
        return RequirementProject::query()
            ->where('requirement_id', $requirementId)
            ->where('project_id', $projectId)
            ->firstOrFail();
    }

    /**
     * @return array{update: bool, transition: bool, release: bool, forceRelease: bool}
     */
    private function projectAbilityMatrix(
        Request $request,
        Project $project,
    ): array {
        $probe = new ProjectVersion(['project_id' => $project->id]);
        $probe->setRelation('project', $project);
        $user = $request->user();

        return [
            'update' => $user->can('update', $probe),
            'transition' => $user->can('transition', $probe),
            'release' => $user->can('release', $probe),
            'forceRelease' => $user->can('forceRelease', $probe),
        ];
    }

    /**
     * @param  array{update: bool, transition: bool, release: bool, forceRelease: bool}  $abilityMatrix
     */
    private function presentSummary(
        ProjectVersion $version,
        array $abilityMatrix,
    ): ProjectVersion {
        $abilityMatrix['delete'] = $abilityMatrix['update']
            && $version->status === ProjectVersionStatus::DRAFT
            && (int) $version->requirement_links_count === 0
            && (int) $version->histories_count === 0;

        $version->setAttribute('ability_matrix', $abilityMatrix);

        return $version;
    }

    private function present(
        ProjectVersion $version,
        User $actor,
    ): ProjectVersion {
        $isRequester = $actor->user_type === UserType::SYSTEM_USER->value;
        $relations = [
            'project:id,name,system_type,manager_id',
            'owner:id,display_name',
        ];

        if ($isRequester) {
            $ownedRequirement = fn ($query) => $query
                ->where('submitter_id', $actor->id);
            $version->load([
                ...$relations,
                'requirementLinks' => fn ($query) => $query
                    ->whereHas('requirement', $ownedRequirement)
                    ->orderBy('id'),
                'requirementLinks.requirement:id,title,status,priority',
            ])->loadCount([
                'requirementLinks as requirement_links_count' => fn ($query) => $query
                    ->whereHas('requirement', $ownedRequirement),
            ]);
            $version->setAttribute('histories_count', 0);
            $version->unsetRelation('histories');
            $version->unsetRelation('releaseSnapshot');
        } else {
            $version->load([
                ...$relations,
                'requirementLinks' => static fn ($query) => $query->orderBy('id'),
                'requirementLinks.requirement:id,title,status,priority',
                'histories' => static fn ($query) => $query
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(self::RECENT_HISTORY_LIMIT),
                'histories.actor:id,display_name',
                'releaseSnapshot.releasedBy:id,display_name',
            ])->loadCount(['requirementLinks', 'histories']);
        }

        $requirementIds = $version->requirementLinks
            ->pluck('requirement_id')
            ->unique()
            ->values();

        $taskCount = $requirementIds->isEmpty()
            ? 0
            : Task::query()
                ->where('project_id', $version->project_id)
                ->whereIn('requirement_id', $requirementIds)
                ->count();
        $defectCount = $requirementIds->isEmpty()
            ? 0
            : Defect::query()
                ->where('project_id', $version->project_id)
                ->whereIn('requirement_id', $requirementIds)
                ->count();

        $version->setAttribute('scope_task_count', $taskCount);
        $version->setAttribute('scope_defect_count', $defectCount);

        if ($isRequester) {
            $version->setAttribute('gate_result', null);
        } else {
            $target = $this->defaultGateTarget($version->status);
            $gate = $this->releaseGateService->check($version, $target);
            $version->setAttribute('gate_result', [
                'target_status' => $target->value,
                'target_status_code' => $target->name,
                ...$gate->jsonSerialize(),
            ]);
        }

        return $version;
    }

    private function defaultGateTarget(
        ProjectVersionStatus $status,
    ): ProjectVersionStatus {
        return match ($status) {
            ProjectVersionStatus::DRAFT => ProjectVersionStatus::PLANNED,
            ProjectVersionStatus::PLANNED => ProjectVersionStatus::IN_DEVELOPMENT,
            ProjectVersionStatus::IN_DEVELOPMENT => ProjectVersionStatus::IN_TESTING,
            ProjectVersionStatus::IN_TESTING => ProjectVersionStatus::READY_TO_RELEASE,
            ProjectVersionStatus::READY_TO_RELEASE => ProjectVersionStatus::RELEASED,
            ProjectVersionStatus::RELEASED,
            ProjectVersionStatus::ARCHIVED => ProjectVersionStatus::ARCHIVED,
        };
    }

    /**
     * @return list<int>
     */
    private function statusValues(): array
    {
        return array_map(
            static fn (ProjectVersionStatus $status): int => $status->value,
            ProjectVersionStatus::cases(),
        );
    }
}
