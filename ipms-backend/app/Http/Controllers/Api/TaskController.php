<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\HoldTaskRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\TransitionTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Scopes\TaskScope;
use App\Services\TaskWorkflowService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class TaskController extends Controller
{
    public function __construct(
        private readonly TaskWorkflowService $workflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = TaskScope::apply($this->resourceQuery(), $request->user());

        if ($request->has('status')) {
            $query->where('status', $request->integer('status'));
        }
        if ($request->has('priority')) {
            $query->where('priority', $request->integer('priority'));
        }
        if ($request->has('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }
        if ($request->has('requirement_id')) {
            $query->where('requirement_id', $request->integer('requirement_id'));
        }
        if ($request->has('assignee_id')) {
            $query->where('assignee_id', $request->integer('assignee_id'));
        }
        if ($request->has('keyword')) {
            $query->where('title', 'like', '%'.$request->input('keyword').'%');
        }

        $pageSize = min(max($request->integer('page_size', 20), 1), 100);
        $paginator = $query
            ->orderBy('due_date')
            ->orderBy('priority')
            ->paginate($pageSize);

        return ApiResponse::paginated(
            $paginator,
            fn (Task $task): array => (new TaskResource($task))->resolve($request),
        );
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $project = Project::query()->findOrFail((int) $validated['project_id']);
        Gate::forUser($request->user())->authorize(
            'createForProject',
            [Task::class, $project],
        );

        $task = $this->workflow->create($validated, $request->user());

        return ApiResponse::success(
            (new TaskResource($task))->resolve($request),
            'Task created.',
            201,
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $task = $this->resourceQuery()->findOrFail($id);
        Gate::forUser($request->user())->authorize('view', $task);

        return ApiResponse::success(
            (new TaskResource($task))->resolve($request),
        );
    }

    public function update(UpdateTaskRequest $request, int $id): JsonResponse
    {
        $task = $this->resourceQuery()->findOrFail($id);
        $updated = $this->workflow->update(
            $task,
            $request->validated(),
            $request->user(),
        );

        return ApiResponse::success(
            (new TaskResource($updated))->resolve($request),
            'Task updated.',
        );
    }

    public function claim(Request $request, int $id): JsonResponse
    {
        $task = $this->resourceQuery()->findOrFail($id);
        Gate::forUser($request->user())->authorize('claim', $task);

        $task = $this->workflow->claim($task, $request->user());

        return ApiResponse::success(
            (new TaskResource($task))->resolve($request),
            'Task claimed.',
        );
    }

    public function transition(TransitionTaskRequest $request, int $id): JsonResponse
    {
        $task = Task::query()->findOrFail($id);
        $validated = $request->validated();
        $task = $this->workflow->transition(
            $task,
            TaskStatus::from((int) $validated['status']),
            $request->user(),
            $validated['reason'] ?? null,
        );

        return ApiResponse::success(
            (new TaskResource($task))->resolve($request),
            'Task status updated.',
        );
    }

    public function hold(HoldTaskRequest $request, int $id): JsonResponse
    {
        $task = Task::query()->findOrFail($id);
        $task = $this->workflow->hold(
            $task,
            $request->user(),
            $request->validated('reason'),
        );

        return ApiResponse::success(
            (new TaskResource($task))->resolve($request),
            'Task held.',
        );
    }

    private function resourceQuery(): Builder
    {
        return Task::query()->with([
            'assignee:id,display_name',
            'requirement:id,title,status',
            'project:id,name,manager_id,supplier_org_id',
        ]);
    }

    private function present(Task $task): Task
    {
        return $task->fresh([
            'assignee:id,display_name',
            'requirement:id,title,status',
            'project:id,name,manager_id,supplier_org_id',
        ]);
    }
}
