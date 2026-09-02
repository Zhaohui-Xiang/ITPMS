<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Http\Requests\ReviewRequirementRequest;
use App\Http\Requests\StoreRequirementRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateRequirementRequest;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Task;
use App\Scopes\ProjectScope;
use App\Scopes\RequirementScope;
use App\Services\RequirementWorkflowService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RequirementController extends Controller
{
    public function __construct(
        private readonly RequirementWorkflowService $workflow,
    ) {}

    /**
     * 需求列表（权限过滤 + 按状态/项目/优先级筛选）
     * GET /api/requirements
     */
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
            'version_scope' => ['sometimes', 'in:unplanned'],
            'project_id' => $projectRules,
        ]);
        $versionScope = $validatedFilters['version_scope'] ?? null;
        $projectId = isset($validatedFilters['project_id'])
            ? (int) $validatedFilters['project_id']
            : null;

        if ($projectId !== null) {
            if ($versionScope === 'unplanned') {
                ProjectScope::apply(Project::query(), $user)
                    ->findOrFail($projectId);
            } else {
                $targetProject = Project::query()->findOrFail($projectId);
                Gate::forUser($user)->authorize('view', $targetProject);
            }
        }

        $relations = [
            'submitter:id,display_name,username',
            'reviewer:id,display_name,username',
        ];
        if ($versionScope === 'unplanned') {
            $relations['projects'] = static fn ($query) => $query
                ->select(['projects.id', 'projects.name'])
                ->where('projects.id', $projectId);
        } else {
            $relations[] = 'projects:id,name';
        }

        $query = Requirement::with($relations);
        $query = RequirementScope::apply($query, $user);

        // 按状态筛选
        if ($request->has('status')) {
            $query->where('status', $request->integer('status'));
        }

        // 按优先级筛选
        if ($request->has('priority')) {
            $query->where('priority', $request->integer('priority'));
        }

        // 按项目筛选
        if ($request->has('project_id')) {
            $projectId = $request->integer('project_id');
            $query->whereHas('projects', function ($q) use ($projectId) {
                $q->where('projects.id', $projectId);
            });
        }

        if ($versionScope === 'unplanned') {
            $projectId = $request->integer('project_id');
            $query->whereHas('projectLinks', function ($q) use ($projectId) {
                $q
                    ->where('project_id', $projectId)
                    ->whereNull('project_version_id');
            });
        }

        // 按需求类型筛选
        if ($request->has('requirement_type')) {
            $query->where('requirement_type', $request->integer('requirement_type'));
        }

        // 搜索标题
        if ($request->has('keyword')) {
            $query->where('title', 'like', '%'.$request->input('keyword').'%');
        }

        $pageSize = min(max($request->integer('page_size', 20), 1), 100);
        $paginator = $query->orderBy('updated_at', 'desc')->paginate($pageSize);

        return ApiResponse::paginated($paginator);
    }

    /**
     * 创建需求（系统用户/IT用户）
     * POST /api/requirements
     */
    public function store(StoreRequirementRequest $request): JsonResponse
    {
        $user = $request->user();

        $requirement = $this->workflow->submit($request->validated(), $user);

        return response()->json([
            'code' => 201,
            'message' => '需求创建成功',
            'data' => $requirement->load(['submitter:id,display_name', 'projects:id,name']),
        ], 201);
    }

    /**
     * 需求详情（含关联项目、子任务、版本历史）
     * GET /api/requirements/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $requirement = Requirement::with([
            'submitter:id,display_name,username,user_type',
            'reviewer:id,display_name,username',
            'devLead:id,display_name,username',
            'creator:id,display_name',
            'updater:id,display_name',
            'projects:id,name,system_type',
            'tasks' => function ($q) {
                $q->with('assignee:id,display_name')->orderBy('created_at', 'desc');
            },
            'versions' => function ($q) {
                $q->with('changedBy:id,display_name')->orderBy('version_number', 'desc');
            },
            'attachments' => function ($q) {
                $q->with('uploader:id,display_name')->orderBy('uploaded_at', 'desc');
            },
        ])->findOrFail($id);

        // 行级权限检查
        if (! $user->can('view', $requirement)) {
            return response()->json(['code' => 403, 'message' => '您无权查看该需求'], 403);
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $requirement,
        ]);
    }

    /**
     * 编辑需求（自动生成版本记录）
     * PUT /api/requirements/{id}
     */
    public function update(UpdateRequirementRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        $requirement = Requirement::findOrFail($id);

        if (! $user->can('update', $requirement)) {
            return ApiResponse::error('REQUIREMENT_UPDATE_FORBIDDEN', 'You are not allowed to edit this requirement.', 403);
        }

        $result = $this->workflow->update(
            $requirement,
            $user,
            $request->validated(),
        );

        return ApiResponse::success(
            $result->requirement,
            $result->message(),
        );
    }

    /**
     * 审核需求（通过/驳回）
     * POST /api/requirements/{id}/review
     */
    public function review(ReviewRequirementRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        $requirement = Requirement::findOrFail($id);

        if (! $user->can('approve', $requirement)) {
            return ApiResponse::error(
                'REQUIREMENT_REVIEW_FORBIDDEN',
                'You are not allowed to review this requirement.',
                403,
            );
        }

        $action = $request->string('action')->toString();
        $comment = $request->input('comment');
        $requirement = $this->workflow->review(
            $requirement,
            $user,
            $action,
            $comment,
        );

        $message = $action === 'approve'
            ? 'Requirement approved.'
            : 'Requirement rejected.';

        return ApiResponse::success($requirement, $message);
    }

    /**
     * 状态流转（含权限校验）
     * POST /api/requirements/{id}/status
     */
    public function transition(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $requirement = Requirement::findOrFail($id);

        if (! $user->can('transition', $requirement)) {
            return ApiResponse::error(
                'REQUIREMENT_TRANSITION_FORBIDDEN',
                'You are not allowed to change this requirement delivery status.',
                403,
            );
        }

        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'status' => ['required', 'integer', 'in:2,3,4,5,6,7'],
        ]);
        $targetStatus = ProjectDeliveryStatus::from((int) $validated['status']);

        $this->workflow->transitionProjectDelivery(
            $requirement,
            (int) $validated['project_id'],
            $targetStatus,
            $user,
        );

        return ApiResponse::success(
            $requirement->fresh(['projectLinks']),
            'Project delivery status updated.',
        );
    }

    public function resubmit(UpdateRequirementRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        $requirement = Requirement::findOrFail($id);

        if (! $user->can('update', $requirement)
            || $requirement->submitter_id !== $user->id) {
            return ApiResponse::error(
                'REQUIREMENT_RESUBMIT_FORBIDDEN',
                'Only the original requester may resubmit this requirement.',
                403,
            );
        }

        $requirement = $this->workflow->resubmit(
            $requirement,
            $user,
            $request->validated(),
        );

        return ApiResponse::success($requirement, 'Requirement resubmitted.');
    }

    /**
     * 版本历史列表
     * GET /api/requirements/{id}/versions
     */
    public function versions(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $requirement = Requirement::findOrFail($id);

        if (! $user->can('view', $requirement)) {
            return response()->json(['code' => 403, 'message' => '您无权查看该需求'], 403);
        }

        $versions = $requirement->versions()
            ->with('changedBy:id,display_name')
            ->orderBy('version_number', 'desc')
            ->get();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $versions,
        ]);
    }

    /**
     * 查看某个版本详情
     * GET /api/requirements/{id}/versions/{vid}
     */
    public function versionDetail(Request $request, int $id, int $vid): JsonResponse
    {
        $user = $request->user();
        $requirement = Requirement::findOrFail($id);

        if (! $user->can('view', $requirement)) {
            return response()->json(['code' => 403, 'message' => '您无权查看该需求'], 403);
        }

        $version = $requirement->versions()
            ->with('changedBy:id,display_name')
            ->where('version_number', $vid)
            ->firstOrFail();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $version,
        ]);
    }

    /**
     * 在需求下创建子任务
     * POST /api/requirements/{id}/tasks
     */
    public function storeTask(StoreTaskRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        $requirement = Requirement::findOrFail($id);

        if (! $user->can('view', $requirement)) {
            return response()->json(['code' => 403, 'message' => '您无权操作该需求'], 403);
        }

        // 取需求关联的第一个项目作为任务的 project_id
        $firstProject = $requirement->projects()->first();
        $projectId = $firstProject ? $firstProject->id : null;

        $task = Task::create([
            'requirement_id' => $requirement->id,
            'project_id' => $projectId,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'assignee_id' => $request->input('assignee_id'),
            'priority' => $request->input('priority'),
            'due_date' => $request->input('due_date'),
            'remind_days_before' => $request->integer('remind_days_before', 1),
            'estimated_hours' => $request->input('estimated_hours'),
            'status' => 1, // 待开始
            'created_by_id' => $user->id,
        ]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 3,
            'action_type' => 1,
            'target_type' => 'task',
            'target_id' => $task->id,
            'target_name' => $task->title,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '子任务创建成功',
            'data' => $task->load('assignee:id,display_name'),
        ], 201);
    }
}
