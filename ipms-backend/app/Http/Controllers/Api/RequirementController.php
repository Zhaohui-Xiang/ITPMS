<?php

namespace App\Http\Controllers\Api;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\TaskStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewRequirementRequest;
use App\Http\Requests\StoreRequirementRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateRequirementRequest;
use App\Http\Resources\RequirementResource;
use App\Http\Resources\TaskResource;
use App\Models\Defect;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Task;
use App\Models\User;
use App\Scopes\ProjectScope;
use App\Scopes\RequirementScope;
use App\Services\RequirementWorkflowService;
use App\Services\TaskWorkflowService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class RequirementController extends Controller
{
    public function __construct(
        private readonly RequirementWorkflowService $workflow,
        private readonly TaskWorkflowService $taskWorkflow,
    ) {}

    public function projectOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('requirement.create'), 403);
        $query = Project::query()->select(['id', 'name'])->where('status', '<>', 3);
        if ($user->user_type !== UserType::SYSTEM_USER->value) {
            ProjectScope::apply($query, $user);
        }
        $pageSize = min(max($request->integer('page_size', 20), 1), 100);
        return ApiResponse::paginated($query->orderBy('name')->orderBy('id')->paginate($pageSize));
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $projectRules = [
            $request->has('version_scope') ? 'required' : 'sometimes',
            'integer',
        ];

        if ($request->input('version_scope') !== 'unplanned') {
            $projectRules[] = 'exists:projects,id';
        }

        $validatedFilters = $request->validate([
            'version_scope' => ['sometimes', 'string', 'in:unplanned'],
            'project_id' => $projectRules,
        ]);
        $versionScope = $validatedFilters['version_scope'] ?? null;
        $projectId = isset($validatedFilters['project_id'])
            ? (int) $validatedFilters['project_id']
            : null;

        if ($projectId !== null) {
            if ($versionScope === 'unplanned') {
                ProjectScope::apply(Project::query(), $user)->findOrFail($projectId);
            } else {
                $targetProject = Project::query()->findOrFail($projectId);
                Gate::forUser($user)->authorize('view', $targetProject);
            }
        }

        $scopedProjectId = $versionScope === 'unplanned' ? $projectId : null;
        $query = RequirementScope::apply(
            $this->resourceQuery($user, $scopedProjectId),
            $user,
        );

        foreach (['status', 'priority', 'requirement_type'] as $filter) {
            if ($request->has($filter)) {
                $query->where($filter, $request->integer($filter));
            }
        }

        if ($projectId !== null) {
            $query->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery
                ->where('projects.id', $projectId));
        }

        if ($versionScope === 'unplanned') {
            $query->whereHas('projectLinks', fn (Builder $linkQuery): Builder => $linkQuery
                ->where('project_id', $projectId)
                ->whereNull('project_version_id'));
        }

        if ($request->has('keyword')) {
            $query->where('title', 'like', '%'.$request->input('keyword').'%');
        }

        $pageSize = min(max($request->integer('page_size', 20), 1), 100);
        $paginator = $query->orderByDesc('updated_at')->paginate($pageSize);

        return ApiResponse::paginated(
            $paginator,
            fn (Requirement $requirement): array => (new RequirementResource($requirement))
                ->resolve($request),
        );
    }

    public function store(StoreRequirementRequest $request): JsonResponse
    {
        $requirement = $this->workflow->submit(
            $request->validated(),
            $request->user(),
        );

        return ApiResponse::success(
            (new RequirementResource($this->present($requirement, $request->user())))->resolve($request),
            'Requirement created.',
            201,
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $requirement = $this->resourceQuery($request->user())->findOrFail($id);
        Gate::forUser($request->user())->authorize('view', $requirement);

        return ApiResponse::success(
            (new RequirementResource($requirement))->resolve($request),
        );
    }

    public function update(UpdateRequirementRequest $request, int $id): JsonResponse
    {
        $requirement = Requirement::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('update', $requirement);

        $result = $this->workflow->update(
            $requirement,
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success(
            (new RequirementResource($this->present($result->requirement, $request->user())))->resolve($request),
            $result->message(),
        );
    }

    public function executionOwnerOptions(Request $request, int $id): JsonResponse
    {
        $requirement = Requirement::with('projects')->findOrFail($id);
        Gate::forUser($request->user())->authorize('update', $requirement);
        Gate::forUser($request->user())->authorize('assign', $requirement);

        return ApiResponse::success(app(\App\Services\RequirementExecutionOwners::class)->options($requirement));
    }

    public function review(ReviewRequirementRequest $request, int $id): JsonResponse
    {
        $requirement = Requirement::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('approve', $requirement);
        $validated = $request->validated();

        $requirement = $this->workflow->review(
            $requirement,
            $request->user(),
            $validated['action'],
            $validated['comment'] ?? null,
        );

        return ApiResponse::success(
            (new RequirementResource($this->present($requirement, $request->user())))->resolve($request),
            $validated['action'] === 'approve'
                ? 'Requirement approved.'
                : 'Requirement rejected.',
        );
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        $requirement = Requirement::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('transition', $requirement);

        $validated = $request->validate([
            'project_id' => ['required', 'integer:strict', 'exists:projects,id'],
            'status' => ['required', 'integer:strict', 'in:2,3,4,5,6,7'],
        ]);

        $this->workflow->transitionProjectDelivery(
            $requirement,
            (int) $validated['project_id'],
            ProjectDeliveryStatus::from((int) $validated['status']),
            $request->user(),
        );

        return ApiResponse::success(
            (new RequirementResource($this->present($requirement, $request->user())))->resolve($request),
            'Project delivery status updated.',
        );
    }

    public function resubmit(UpdateRequirementRequest $request, int $id): JsonResponse
    {
        $requirement = Requirement::query()->findOrFail($id);
        $user = $request->user();

        if ($requirement->submitter_id !== $user->id) {
            return ApiResponse::error(
                'REQUIREMENT_RESUBMIT_FORBIDDEN',
                'Only the original requester may resubmit this requirement.',
                403,
            );
        }

        Gate::forUser($user)->authorize('update', $requirement);
        $requirement = $this->workflow->resubmit(
            $requirement,
            $user,
            $request->validated(),
        );

        return ApiResponse::success(
            (new RequirementResource($this->present($requirement, $request->user())))->resolve($request),
            'Requirement resubmitted.',
        );
    }

    public function versions(Request $request, int $id): JsonResponse
    {
        $requirement = Requirement::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('view', $requirement);

        return ApiResponse::success(
            $requirement->versions()
                ->with('changedBy:id,display_name')
                ->orderByDesc('version_number')
                ->get(),
        );
    }

    public function versionDetail(
        Request $request,
        int $id,
        int $vid,
    ): JsonResponse {
        $requirement = Requirement::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('view', $requirement);

        $version = $requirement->versions()
            ->with('changedBy:id,display_name')
            ->where('version_number', $vid)
            ->firstOrFail();

        return ApiResponse::success($version);
    }

    public function storeTask(StoreTaskRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();
        $requirement = Requirement::query()->findOrFail($id);
        $project = Project::query()->findOrFail((int) $validated['project_id']);
        Gate::forUser($request->user())->authorize(
            'createTask',
            [$requirement, $project],
        );

        $task = $this->taskWorkflow->create($validated, $request->user());

        return ApiResponse::success(
            (new TaskResource($task))->resolve($request),
            'Task created.',
            201,
        );
    }

    private function resourceQuery(
        User $actor,
        ?int $scopedProjectId = null,
    ): Builder {
        return Requirement::query()->with([
            'submitter:id,display_name',
            'reviewer:id,display_name',
            'devLead:id,display_name',
            'attachments' => static fn ($query) => $query
                ->with('uploader:id,display_name')
                ->orderByDesc('uploaded_at')
                ->orderByDesc('id'),
            'projects' => function ($projectQuery) use ($actor, $scopedProjectId): void {
                $projectQuery->select([
                    'projects.id',
                    'projects.name',
                    'projects.system_type',
                ]);
                $this->constrainProjectQuery($projectQuery, $actor);

                if ($scopedProjectId !== null) {
                    $projectQuery->where('projects.id', $scopedProjectId);
                }
            },
            'projectLinks' => function ($linkQuery) use ($actor, $scopedProjectId): void {
                $linkQuery
                    ->select('requirement_project.*')
                    ->selectSub(
                        Task::query()
                            ->selectRaw('count(*)')
                            ->whereColumn(
                                'tasks.requirement_id',
                                'requirement_project.requirement_id',
                            )
                            ->whereColumn(
                                'tasks.project_id',
                                'requirement_project.project_id',
                            ),
                        'task_total',
                    )
                    ->selectSub(
                        Task::query()
                            ->selectRaw('count(*)')
                            ->whereColumn(
                                'tasks.requirement_id',
                                'requirement_project.requirement_id',
                            )
                            ->whereColumn(
                                'tasks.project_id',
                                'requirement_project.project_id',
                            )
                            ->where('tasks.status', TaskStatus::COMPLETED->value),
                        'task_completed',
                    )
                    ->selectSub(
                        Defect::query()
                            ->selectRaw('count(*)')
                            ->whereColumn(
                                'defects.requirement_id',
                                'requirement_project.requirement_id',
                            )
                            ->whereColumn(
                                'defects.project_id',
                                'requirement_project.project_id',
                            )
                            ->whereIn('defects.severity', [
                                DefectSeverity::FATAL->value,
                                DefectSeverity::SERIOUS->value,
                            ])
                            ->where('defects.status', '!=', DefectStatus::CLOSED->value),
                        'open_severe_defect_count',
                    )
                    ->with([
                        'project:id,name,system_type,manager_id,supplier_org_id',
                        'project.manager:id,display_name',
                        'projectVersion:id,project_id,code,name,status,owner_id',
                        'projectVersion.owner:id,display_name',
                    ]);
                $this->constrainProjectLinks($linkQuery, $actor);

                if ($scopedProjectId !== null) {
                    $linkQuery->where('project_id', $scopedProjectId);
                }
            },
        ]);
    }

    private function constrainProjectQuery(mixed $query, User $actor): void
    {
        if ($actor->user_type !== UserType::SUPPLIER->value) {
            return;
        }

        $orgIds = $actor->getSupplierDescendantOrgIds();
        $orgIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('supplier_org_id', $orgIds);
    }

    private function constrainProjectLinks(mixed $query, User $actor): void
    {
        if ($actor->user_type !== UserType::SUPPLIER->value) {
            return;
        }

        $query->whereHas(
            'project',
            function ($projectQuery) use ($actor): void {
                $this->constrainProjectQuery($projectQuery, $actor);
            },
        );
    }

    private function present(
        Requirement $requirement,
        User $actor,
    ): Requirement {
        return $this->resourceQuery($actor)->findOrFail($requirement->id);
    }
}
