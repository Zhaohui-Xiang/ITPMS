<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Task;
use App\Scopes\TaskScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * 任务列表（权限过滤 + 筛选 + 分页）
     * GET /api/tasks
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Task::with([
            'assignee:id,display_name,username',
            'requirement:id,title',
            'project:id,name',
            'creator:id,display_name',
        ]);
        $query = TaskScope::apply($query, $user);

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
            $query->where('project_id', $request->integer('project_id'));
        }

        // 按需求筛选
        if ($request->has('requirement_id')) {
            $query->where('requirement_id', $request->integer('requirement_id'));
        }

        // 按负责人筛选
        if ($request->has('assignee_id')) {
            $query->where('assignee_id', $request->integer('assignee_id'));
        }

        // 搜索标题
        if ($request->has('search')) {
            $query->where('title', 'like', '%' . $request->input('search') . '%');
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $paginator = $query->orderBy('due_date', 'asc')
            ->orderBy('priority', 'asc')
            ->paginate($perPage);

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
     * 创建任务
     * POST /api/tasks
     */
    public function store(StoreTaskRequest $request): JsonResponse
    {
        $user = $request->user();

        $task = Task::create([
            'requirement_id' => $request->input('requirement_id'),
            'project_id' => $request->input('project_id'),
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'assignee_id' => $request->input('assignee_id'),
            'priority' => $request->input('priority'),
            'due_date' => $request->input('due_date'),
            'remind_days_before' => $request->integer('remind_days_before', 1),
            'estimated_hours' => $request->input('estimated_hours'),
            'status' => TaskStatus::TODO->value,
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
            'message' => '任务创建成功',
            'data' => $task->load(['assignee:id,display_name', 'requirement:id,title', 'project:id,name']),
        ], 201);
    }

    /**
     * 任务详情
     * GET /api/tasks/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $task = Task::with([
            'assignee:id,display_name,username',
            'requirement:id,title,status',
            'project:id,name',
            'creator:id,display_name',
        ])->findOrFail($id);

        if (!$user->can('view', $task)) {
            return response()->json(['code' => 403, 'message' => '您无权查看该任务'], 403);
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $task,
        ]);
    }

    /**
     * 编辑任务
     * PUT /api/tasks/{id}
     */
    public function update(UpdateTaskRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        $task = Task::findOrFail($id);

        if (!$user->can('update', $task)) {
            return response()->json(['code' => 403, 'message' => '您无权编辑该任务'], 403);
        }

        $task->update($request->only([
            'title', 'description', 'assignee_id', 'priority',
            'due_date', 'remind_days_before', 'estimated_hours', 'actual_hours',
        ]));

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'task',
            'action_type' => 'update',
            'target_type' => 'task',
            'target_id' => $task->id,
            'target_name' => $task->title,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '任务更新成功',
            'data' => $task->fresh(['assignee:id,display_name', 'requirement:id,title']),
        ]);
    }

    /**
     * 认领任务（供应商开发/测试人员认领）
     * POST /api/tasks/{id}/claim
     */
    public function claim(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $task = Task::findOrFail($id);

        if (!$user->can('claim', $task)) {
            return response()->json(['code' => 403, 'message' => '您无权认领任务'], 403);
        }

        if ($task->assignee_id) {
            return response()->json([
                'code' => 422,
                'message' => '该任务已被认领',
            ], 422);
        }

        $task->update([
            'assignee_id' => $user->id,
            'status' => TaskStatus::IN_PROGRESS->value,
        ]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'task',
            'action_type' => 'claim',
            'target_type' => 'task',
            'target_id' => $task->id,
            'target_name' => $task->title,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '任务认领成功',
            'data' => $task->fresh(),
        ]);
    }

    /**
     * 状态变更
     * POST /api/tasks/{id}/status
     */
    public function transition(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $task = Task::findOrFail($id);

        if (!$user->can('transition', $task)) {
            return response()->json(['code' => 403, 'message' => '您无权变更该任务状态'], 403);
        }

        $request->validate([
            'status' => ['required', 'integer', 'in:1,2,3,4'],
        ]);

        $newStatus = $request->integer('status');
        $oldStatus = $task->status;

        $data = ['status' => $newStatus];

        // 完成时记录完成时间
        if ($newStatus === TaskStatus::COMPLETED->value) {
            $data['completed_at'] = now();
        }

        $task->update($data);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'task',
            'action_type' => 'status_change',
            'target_type' => 'task',
            'target_id' => $task->id,
            'target_name' => $task->title,
            'detail' => [
                'from_status' => $oldStatus,
                'from_label' => TaskStatus::from($oldStatus)->label(),
                'to_status' => $newStatus,
                'to_label' => TaskStatus::from($newStatus)->label(),
            ],
        ]);

        return response()->json([
            'code' => 200,
            'message' => '任务状态更新成功',
            'data' => $task->fresh(),
        ]);
    }

    /**
     * 挂起任务
     * POST /api/tasks/{id}/hold
     */
    public function hold(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $task = Task::findOrFail($id);

        if (!$user->can('transition', $task)) {
            return response()->json(['code' => 403, 'message' => '您无权操作该任务'], 403);
        }

        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $task->update([
            'status' => TaskStatus::SUSPENDED->value,
            'suspend_reason' => $request->input('reason'),
        ]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'task',
            'action_type' => 'suspend',
            'target_type' => 'task',
            'target_id' => $task->id,
            'target_name' => $task->title,
            'detail' => ['reason' => $request->input('reason')],
        ]);

        return response()->json([
            'code' => 200,
            'message' => '任务已挂起',
            'data' => $task->fresh(),
        ]);
    }
}
