<?php

namespace App\Http\Controllers\Api;

use App\Enums\DefectStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Http\Requests\StoreDefectRequest;
use App\Models\Defect;
use App\Scopes\DefectScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DefectController extends Controller
{
    /**
     * 缺陷列表（权限过滤 + 筛选 + 分页）
     * GET /api/defects
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Defect::with([
            'reporter:id,display_name,username',
            'assignee:id,display_name,username',
            'requirement:id,title',
            'project:id,name',
        ]);
        $query = DefectScope::apply($query, $user);

        // 按状态筛选
        if ($request->has('status')) {
            $query->where('status', $request->integer('status'));
        }

        // 按严重程度筛选
        if ($request->has('severity')) {
            $query->where('severity', $request->integer('severity'));
        }

        // 按缺陷类型筛选
        if ($request->has('defect_type')) {
            $query->where('defect_type', $request->integer('defect_type'));
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
        $paginator = $query->orderBy('severity', 'asc')
            ->orderBy('created_at', 'desc')
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
     * 提交缺陷
     * POST /api/defects
     */
    public function store(StoreDefectRequest $request): JsonResponse
    {
        $user = $request->user();

        // 从需求获取 project_id
        $requirement = \App\Models\Requirement::findOrFail($request->input('requirement_id'));
        $firstProject = $requirement->projects()->first();
        $projectId = $firstProject ? $firstProject->id : null;

        $data = [
            'requirement_id' => $request->input('requirement_id'),
            'project_id' => $projectId,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'severity' => $request->input('severity'),
            'defect_type' => $request->input('defect_type'),
            'reporter_id' => $user->id,
            'discovered_at' => now(),
            'discovery_phase' => $request->input('discovery_phase'),
            'status' => DefectStatus::PENDING_CONFIRM->value,
            'created_by_id' => $user->id,
        ];

        // 处理截图上传
        if ($request->hasFile('screenshot')) {
            $data['screenshot'] = $request->file('screenshot')->store('defects/screenshots', 'public');
        }

        $defect = Defect::create($data);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'defect',
            'action_type' => 'create',
            'target_type' => 'defect',
            'target_id' => $defect->id,
            'target_name' => $defect->title,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '缺陷提交成功',
            'data' => $defect->load(['reporter:id,display_name', 'requirement:id,title', 'project:id,name']),
        ], 201);
    }

    /**
     * 缺陷详情
     * GET /api/defects/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $defect = Defect::with([
            'reporter:id,display_name,username',
            'assignee:id,display_name,username',
            'requirement:id,title,status',
            'project:id,name',
            'creator:id,display_name',
        ])->findOrFail($id);

        if (!$user->can('view', $defect)) {
            return response()->json(['code' => 403, 'message' => '您无权查看该缺陷'], 403);
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $defect,
        ]);
    }

    /**
     * 编辑缺陷
     * PUT /api/defects/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $defect = Defect::findOrFail($id);

        if (!$user->can('update', $defect)) {
            return response()->json(['code' => 403, 'message' => '您无权编辑该缺陷'], 403);
        }

        $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'string'],
            'severity' => ['sometimes', 'integer', 'in:1,2,3,4'],
            'defect_type' => ['sometimes', 'integer', 'in:1,2,3,4,5'],
        ]);

        $defect->update($request->only(['title', 'description', 'severity', 'defect_type']));

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'defect',
            'action_type' => 'update',
            'target_type' => 'defect',
            'target_id' => $defect->id,
            'target_name' => $defect->title,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '缺陷更新成功',
            'data' => $defect->fresh(['reporter:id,display_name', 'assignee:id,display_name']),
        ]);
    }

    /**
     * 确认缺陷（IT 项目经理确认缺陷有效性）
     * POST /api/defects/{id}/confirm
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $defect = Defect::findOrFail($id);

        if (!$user->can('confirm', $defect)) {
            return response()->json(['code' => 403, 'message' => '您无权确认缺陷'], 403);
        }

        if ($defect->status !== DefectStatus::PENDING_CONFIRM->value) {
            return response()->json([
                'code' => 422,
                'message' => '该缺陷当前状态不允许确认操作',
            ], 422);
        }

        $defect->update(['status' => DefectStatus::CONFIRMED->value]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'defect',
            'action_type' => 'confirm',
            'target_type' => 'defect',
            'target_id' => $defect->id,
            'target_name' => $defect->title,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '缺陷已确认',
            'data' => $defect->fresh(),
        ]);
    }

    /**
     * 指派修复（将缺陷指派给供应商或开发人员）
     * POST /api/defects/{id}/assign
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $defect = Defect::findOrFail($id);

        if (!$user->can('assign', $defect)) {
            return response()->json(['code' => 403, 'message' => '您无权指派缺陷'], 403);
        }

        $request->validate([
            'assignee_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $defect->update([
            'assignee_id' => $request->input('assignee_id'),
            'status' => DefectStatus::FIXING->value,
        ]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'defect',
            'action_type' => 'assign',
            'target_type' => 'defect',
            'target_id' => $defect->id,
            'target_name' => $defect->title,
            'detail' => ['assignee_id' => $request->input('assignee_id')],
        ]);

        return response()->json([
            'code' => 200,
            'message' => '缺陷已指派',
            'data' => $defect->fresh(['assignee:id,display_name']),
        ]);
    }

    /**
     * 标记修复完成
     * POST /api/defects/{id}/resolve
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $defect = Defect::findOrFail($id);

        if (!$user->can('resolve', $defect)) {
            return response()->json(['code' => 403, 'message' => '您无权标记修复'], 403);
        }

        $request->validate([
            'fix_description' => ['required', 'string'],
        ]);

        $defect->update([
            'status' => DefectStatus::PENDING_RETEST->value,
            'fix_description' => $request->input('fix_description'),
        ]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'defect',
            'action_type' => 'resolve',
            'target_type' => 'defect',
            'target_id' => $defect->id,
            'target_name' => $defect->title,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '修复已标记完成，待复测',
            'data' => $defect->fresh(),
        ]);
    }

    /**
     * 复测（通过/不通过）
     * POST /api/defects/{id}/verify
     */
    public function verify(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $defect = Defect::findOrFail($id);

        if (!$user->can('verify', $defect)) {
            return response()->json(['code' => 403, 'message' => '您无权复测该缺陷'], 403);
        }

        $request->validate([
            'result' => ['required', 'string', 'in:pass,fail'],
            'comment' => ['nullable', 'string'],
        ]);

        $result = $request->input('result');

        if ($result === 'pass') {
            $defect->update([
                'status' => DefectStatus::CLOSED->value,
                'closed_at' => now(),
            ]);
            $message = '复测通过，缺陷已关闭';
        } else {
            $defect->update([
                'status' => DefectStatus::REOPENED->value,
            ]);
            $message = '复测不通过，缺陷已重新打开';
        }

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'defect',
            'action_type' => 'verify',
            'target_type' => 'defect',
            'target_id' => $defect->id,
            'target_name' => $defect->title,
            'detail' => ['result' => $result, 'comment' => $request->input('comment')],
        ]);

        return response()->json([
            'code' => 200,
            'message' => $message,
            'data' => $defect->fresh(),
        ]);
    }

    /**
     * 重新打开已关闭的缺陷
     * POST /api/defects/{id}/reopen
     */
    public function reopen(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $defect = Defect::findOrFail($id);

        if (!$user->can('view', $defect)) {
            return response()->json(['code' => 403, 'message' => '您无权操作该缺陷'], 403);
        }

        if ($defect->status !== DefectStatus::CLOSED->value) {
            return response()->json([
                'code' => 422,
                'message' => '只有已关闭的缺陷才能重新打开',
            ], 422);
        }

        $defect->update([
            'status' => DefectStatus::REOPENED->value,
            'closed_at' => null,
        ]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'defect',
            'action_type' => 'reopen',
            'target_type' => 'defect',
            'target_id' => $defect->id,
            'target_name' => $defect->title,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '缺陷已重新打开',
            'data' => $defect->fresh(),
        ]);
    }
}
