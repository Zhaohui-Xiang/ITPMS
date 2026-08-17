<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequirementStatus;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequirementController extends Controller
{
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
        if ($request->has('search')) {
            $query->where('title', 'like', '%' . $request->input('search') . '%');
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $paginator = $query->orderBy('updated_at', 'desc')->paginate($perPage);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'items' => $paginator->items(),
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * 创建需求（系统用户/IT用户）
     * POST /api/requirements
     */
    public function store(StoreRequirementRequest $request): JsonResponse
    {
        $user = $request->user();

        $requirement = Requirement::create([
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'priority' => $request->input('priority'),
            'requirement_type' => $request->input('requirement_type'),
            'expected_completion_date' => $request->input('expected_completion_date'),
            'submitter_id' => $user->id,
            'submitted_at' => now(),
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'version' => 1,
            'created_by_id' => $user->id,
        ]);

        // 关联项目
        $requirement->projects()->sync($request->input('project_ids', []));

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'requirement',
            'action_type' => 'create',
            'target_type' => 'requirement',
            'target_id' => $requirement->id,
            'target_name' => $requirement->title,
        ]);

        return response()->json([
            'code' => 200,
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
        if (!$user->can('view', $requirement)) {
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

        if (!$user->can('update', $requirement)) {
            return response()->json(['code' => 403, 'message' => '您无权编辑该需求'], 403);
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
                'change_summary' => '编辑需求，共变更 ' . count($changes) . ' 个字段',
            ]);
        }

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'requirement',
            'action_type' => 'update',
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

        if (!$user->can('approve', $requirement)) {
            return response()->json(['code' => 403, 'message' => '您无权审核该需求'], 403);
        }

        $action = $request->input('action');
        $comment = $request->input('comment');

        if ($action === 'approve') {
            $requirement->update([
                'status' => RequirementStatus::ASSIGNED->value,
                'reviewer_id' => $user->id,
                'review_comment' => $comment ?? '审核通过',
                'reviewed_at' => now(),
            ]);
            $message = '需求审核通过';
        } else {
            // 驳回：回到待审核状态，保留审核意见
            $requirement->update([
                'status' => RequirementStatus::PENDING_REVIEW->value,
                'reviewer_id' => $user->id,
                'review_comment' => $comment,
                'reviewed_at' => now(),
            ]);
            $message = '需求已驳回';
        }

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'requirement',
            'action_type' => $action,
            'target_type' => 'requirement',
            'target_id' => $requirement->id,
            'target_name' => $requirement->title,
            'detail' => ['comment' => $comment],
        ]);

        return response()->json([
            'code' => 200,
            'message' => $message,
            'data' => $requirement->fresh(),
        ]);
    }

    /**
     * 状态流转（含权限校验）
     * POST /api/requirements/{id}/status
     */
    public function transition(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $requirement = Requirement::findOrFail($id);

        if (!$user->can('transition', $requirement)) {
            return response()->json(['code' => 403, 'message' => '您无权变更该需求状态'], 403);
        }

        $request->validate([
            'status' => ['required', 'integer', 'in:2,3,4,5,6,7'],
        ]);

        $newStatus = $request->integer('status');

        // 状态流转合法性校验
        $allowedTransitions = $this->getAllowedTransitions($requirement->status);
        if (!in_array($newStatus, $allowedTransitions)) {
            $currentLabel = RequirementStatus::from($requirement->status)->label();
            $targetLabel = RequirementStatus::from($newStatus)->label();
            return response()->json([
                'code' => 422,
                'message' => "不允许从「{$currentLabel}」直接变更为「{$targetLabel}」",
            ], 422);
        }

        $oldStatus = $requirement->status;
        $requirement->update(['status' => $newStatus]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'requirement',
            'action_type' => 'status_change',
            'target_type' => 'requirement',
            'target_id' => $requirement->id,
            'target_name' => $requirement->title,
            'detail' => [
                'from_status' => $oldStatus,
                'from_label' => RequirementStatus::from($oldStatus)->label(),
                'to_status' => $newStatus,
                'to_label' => RequirementStatus::from($newStatus)->label(),
            ],
        ]);

        return response()->json([
            'code' => 200,
            'message' => '状态更新成功',
            'data' => $requirement->fresh(),
        ]);
    }

    /**
     * 版本历史列表
     * GET /api/requirements/{id}/versions
     */
    public function versions(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $requirement = Requirement::findOrFail($id);

        if (!$user->can('view', $requirement)) {
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

        if (!$user->can('view', $requirement)) {
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

        if (!$user->can('view', $requirement)) {
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
            'module' => 'task',
            'action_type' => 'create',
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

    /**
     * 获取状态流转允许的目标状态
     */
    private function getAllowedTransitions(int $currentStatus): array
    {
        return match ($currentStatus) {
            RequirementStatus::PENDING_REVIEW->value => [
                RequirementStatus::ASSIGNED->value,
            ],
            RequirementStatus::ASSIGNED->value => [
                RequirementStatus::IN_DEVELOPMENT->value,
            ],
            RequirementStatus::IN_DEVELOPMENT->value => [
                RequirementStatus::IN_TESTING->value,
                RequirementStatus::PENDING_DEPLOY->value,
            ],
            RequirementStatus::IN_TESTING->value => [
                RequirementStatus::PENDING_DEPLOY->value,
                RequirementStatus::IN_DEVELOPMENT->value, // 测试不通过打回开发
            ],
            RequirementStatus::PENDING_DEPLOY->value => [
                RequirementStatus::DEPLOYED->value,
            ],
            RequirementStatus::DEPLOYED->value => [
                RequirementStatus::ACCEPTED->value,
                RequirementStatus::IN_DEVELOPMENT->value, // 上线后发现问题打回
            ],
            RequirementStatus::ACCEPTED->value => [],
            default => [],
        };
    }
}
