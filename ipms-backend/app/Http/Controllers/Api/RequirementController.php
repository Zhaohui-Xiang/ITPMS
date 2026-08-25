<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Http\Requests\ReviewRequirementRequest;
use App\Http\Requests\StoreRequirementRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateRequirementRequest;
use App\Models\Requirement;
use App\Models\RequirementVersion;
use App\Models\Task;
use App\Scopes\RequirementScope;
use App\Services\RequirementWorkflowService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $query = Requirement::with([
            'submitter:id,display_name,username',
            'reviewer:id,display_name,username',
            'projects:id,name',
        ]);
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

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 2,
            'action_type' => 1,
            'target_type' => 'requirement',
            'target_id' => $requirement->id,
            'target_name' => $requirement->title,
        ]);

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
            return response()->json(['code' => 403, 'message' => '您无权编辑该需求'], 403);
        }

        if ($requirement->isRejectedForResubmission()) {
            if ($requirement->submitter_id !== $user->id) {
                return ApiResponse::error(
                    'REQUIREMENT_RESUBMIT_FORBIDDEN',
                    'Only the original requester may edit and resubmit.',
                    403,
                );
            }

            $requirement = $this->workflow->resubmit(
                $requirement,
                $user,
                $request->validated(),
            );

            AuditLogger::log($user->id, [
                'user_name' => $user->username,
                'user_display_name' => $user->display_name,
                'user_type' => $user->user_type,
                'module' => 2,
                'action_type' => 2,
                'target_type' => 'requirement',
                'target_id' => $requirement->id,
                'target_name' => $requirement->title,
            ]);

            return ApiResponse::success($requirement, 'Requirement resubmitted.');
        }
        // 定义需要追踪变更的核心字段
        $trackedFields = ['title', 'description', 'priority', 'requirement_type', 'expected_completion_date'];
        $changes = [];
        $hasChanges = false;

        foreach ($trackedFields as $field) {
            if ($request->has($field)) {
                $oldValue = $requirement->$field;
                $newValue = $request->input($field);

                if ($oldValue != $newValue) {
                    $hasChanges = true;
                    $fieldNames = [
                        'title' => '需求标题',
                        'description' => '需求描述',
                        'priority' => '优先级',
                        'requirement_type' => '需求类型',
                        'expected_completion_date' => '期望完成时间',
                    ];

                    $changes[] = [
                        'field' => $field,
                        'field_name' => $fieldNames[$field] ?? $field,
                        'old_value' => (string) $oldValue,
                        'new_value' => (string) $newValue,
                    ];
                }
            }
        }

        // 更新需求字段
        $data = $request->only($trackedFields);
        $data['updated_by_id'] = $user->id;
        $requirement->update($data);

        // 更新项目关联
        if ($request->has('project_ids')) {
            $requirement->projects()->sync($request->input('project_ids'));
        }

        // 更新开发负责人
        if ($request->has('dev_lead_id')) {
            $requirement->update(['dev_lead_id' => $request->input('dev_lead_id')]);
        }

        // 如有核心字段变更，生成版本记录
        if ($hasChanges) {
            $newVersion = $requirement->version + 1;
            $requirement->update(['version' => $newVersion]);

            RequirementVersion::create([
                'requirement_id' => $requirement->id,
                'version_number' => $newVersion,
                'changed_by_id' => $user->id,
                'changed_at' => now(),
                'changes' => $changes,
                'change_summary' => '编辑需求，共变更 '.count($changes).' 个字段',
            ]);
        }

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 2,
            'action_type' => 2,
            'target_type' => 'requirement',
            'target_id' => $requirement->id,
            'target_name' => $requirement->title,
            'detail' => $hasChanges ? ['changes' => $changes] : null,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '需求更新成功',
            'data' => $requirement->fresh([
                'submitter:id,display_name',
                'reviewer:id,display_name',
                'projects:id,name',
            ]),
        ]);
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

        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 2,
            'action_type' => 5,
            'target_type' => 'requirement',
            'target_id' => $requirement->id,
            'target_name' => $requirement->title,
            'detail' => ['action' => $action, 'comment' => $comment],
        ]);

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

        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 2,
            'action_type' => 4,
            'target_type' => 'requirement',
            'target_id' => $requirement->id,
            'target_name' => $requirement->title,
            'detail' => [
                'project_id' => (int) $validated['project_id'],
                'to_delivery_status' => $targetStatus->value,
            ],
        ]);

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

        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 2,
            'action_type' => 2,
            'target_type' => 'requirement',
            'target_id' => $requirement->id,
            'target_name' => $requirement->title,
            'detail' => ['version' => $requirement->version],
        ]);

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
