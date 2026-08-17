<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Support\ApiResponse;
use App\Scopes\ProjectScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * 项目列表（权限过滤 + 分页）
     * GET /api/projects
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Project::with(['manager:id,display_name,username', 'supplierOrg:id,name']);
        $query = ProjectScope::apply($query, $user);

        // 按状态筛选
        if ($request->has('status')) {
            $query->where('status', $request->integer('status'));
        }

        // 搜索项目名称
        if ($request->has('keyword')) {
            $query->where('name', 'like', '%' . $request->input('keyword') . '%');
        }

        $pageSize = min(max($request->integer('page_size', 20), 1), 100);
        $paginator = $query->orderBy('updated_at', 'desc')->paginate($pageSize);

        return ApiResponse::paginated($paginator);
    }

    /**
     * 创建项目（仅超管）
     * POST /api/projects
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $user = $request->user();

        $project = Project::create([
            'name' => $request->input('name'),
            'system_type' => $request->input('system_type'),
            'description' => $request->input('description'),
            'status' => $request->integer('status', ProjectStatus::ACTIVE->value),
            'manager_id' => $request->input('manager_id'),
            'supplier_org_id' => $request->input('supplier_org_id'),
            'created_by_id' => $user->id,
        ]);

        // 将创建人自动添加为项目成员
        $project->members()->create([
            'user_id' => $user->id,
            'role_in_project' => 'pm',
            'assigned_by_id' => $user->id,
            'assigned_at' => now(),
        ]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'project',
            'action_type' => 'create',
            'target_type' => 'project',
            'target_id' => $project->id,
            'target_name' => $project->name,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '项目创建成功',
            'data' => $project->load(['manager:id,display_name', 'supplierOrg:id,name']),
        ], 201);
    }

    /**
     * 项目详情
     * GET /api/projects/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $project = Project::with([
            'manager:id,display_name,username',
            'supplierOrg:id,name',
            'creator:id,display_name',
            'members.user:id,display_name,username,user_type',
        ])->findOrFail($id);

        // 行级权限检查
        if (!$user->can('view', $project)) {
            return response()->json(['code' => 403, 'message' => '您无权查看该项目'], 403);
        }

        // 附加统计信息
        $project->requirement_count = $project->requirements()->count();
        $project->task_count = $project->tasks()->count();
        $project->defect_count = $project->defects()->count();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $project,
        ]);
    }

    /**
     * 编辑项目（仅超管）
     * PUT /api/projects/{id}
     */
    public function update(UpdateProjectRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        $project = Project::findOrFail($id);

        $project->update($request->only([
            'name', 'system_type', 'description', 'status', 'manager_id', 'supplier_org_id',
        ]));

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'project',
            'action_type' => 'update',
            'target_type' => 'project',
            'target_id' => $project->id,
            'target_name' => $project->name,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '项目更新成功',
            'data' => $project->load(['manager:id,display_name', 'supplierOrg:id,name']),
        ]);
    }

    /**
     * 删除项目（检查关联需求，有则禁止）
     * DELETE /api/projects/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $project = Project::findOrFail($id);

        // 权限检查
        if (!$user->can('delete', $project)) {
            return response()->json(['code' => 403, 'message' => '仅超级管理员可以删除项目'], 403);
        }

        // 检查是否关联需求
        $requirementCount = $project->requirements()->count();
        if ($requirementCount > 0) {
            return response()->json([
                'code' => 422,
                'message' => "该项目已关联 {$requirementCount} 条需求，无法删除。如需移除，请先将关联的需求移动至其他项目或删除。",
            ], 422);
        }

        $projectName = $project->name;
        $project->delete();

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'project',
            'action_type' => 'delete',
            'target_type' => 'project',
            'target_id' => $id,
            'target_name' => $projectName,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '项目已删除',
        ]);
    }

    /**
     * 归档项目
     * POST /api/projects/{id}/archive
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $project = Project::findOrFail($id);

        if (!$user->can('update', $project)) {
            return response()->json(['code' => 403, 'message' => '仅超级管理员可以归档项目'], 403);
        }

        $project->update(['status' => ProjectStatus::ARCHIVED->value]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'project',
            'action_type' => 'archive',
            'target_type' => 'project',
            'target_id' => $project->id,
            'target_name' => $project->name,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '项目已归档',
            'data' => $project,
        ]);
    }
}
