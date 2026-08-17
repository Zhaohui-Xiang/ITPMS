<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * 组织架构树（按类型筛选）
     * GET /api/organizations
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Organization::query();

        // 按组织类型筛选
        if ($request->has('org_type')) {
            $query->where('org_type', $request->integer('org_type'));
        } else {
            // 默认：供应商用户只能看到自己类型的组织架构
            if ($user->user_type === UserType::SUPPLIER->value) {
                $query->where('org_type', UserType::SUPPLIER->value);
            }
            // 系统用户只能看到自己的组织架构
            if ($user->user_type === UserType::SYSTEM_USER->value) {
                $query->where('org_type', UserType::SYSTEM_USER->value);
            }
        }

        // 只显示活跃节点
        if (!$request->has('include_inactive')) {
            $query->where('is_active', true);
        }

        // 获取根节点并构建树
        $roots = (clone $query)->whereNull('parent_id')
            ->with(['children' => function ($q) use ($request) {
                $q->with(['children' => function ($sq) use ($request) {
                    $sq->with(['children' => function ($ssq) use ($request) {
                        $ssq->with('users:id,display_name,username,user_type');
                    }])->with('users:id,display_name,username,user_type');
                }])->with('users:id,display_name,username,user_type');
            }])
            ->with('users:id,display_name,username,user_type')
            ->orderBy('name')
            ->get();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $roots,
        ]);
    }

    /**
     * 获取子节点
     * GET /api/organizations/{id}/children
     */
    public function children(Request $request, int $id): JsonResponse
    {
        $node = Organization::findOrFail($id);

        $children = $node->children()
            ->with('users:id,display_name,username,user_type')
            ->orderBy('name')
            ->get();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $children,
        ]);
    }

    /**
     * 创建组织节点
     * POST /api/organizations
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // 仅超管可创建组织节点
        if (!$user->isSuperAdmin()) {
            return response()->json(['code' => 403, 'message' => '仅超级管理员可以创建组织节点'], 403);
        }

        $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:100'],
            'org_type' => ['required', 'integer', 'in:1,2,3'],
            'description' => ['nullable', 'string'],
        ]);

        $org = Organization::create([
            'parent_id' => $request->input('parent_id'),
            'name' => $request->input('name'),
            'org_type' => $request->input('org_type'),
            'description' => $request->input('description'),
            'is_active' => true,
        ]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'organization',
            'action_type' => 'create',
            'target_type' => 'organization',
            'target_id' => $org->id,
            'target_name' => $org->name,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '组织节点创建成功',
            'data' => $org,
        ], 201);
    }

    /**
     * 编辑组织节点
     * PUT /api/organizations/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            return response()->json(['code' => 403, 'message' => '仅超级管理员可以编辑组织节点'], 403);
        }

        $org = Organization::findOrFail($id);

        $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $org->update($request->only(['name', 'description', 'is_active']));

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'organization',
            'action_type' => 'update',
            'target_type' => 'organization',
            'target_id' => $org->id,
            'target_name' => $org->name,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '组织节点更新成功',
            'data' => $org,
        ]);
    }

    /**
     * 删除节点
     * DELETE /api/organizations/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            return response()->json(['code' => 403, 'message' => '仅超级管理员可以删除组织节点'], 403);
        }

        $org = Organization::findOrFail($id);

        // 检查是否有子节点
        if ($org->children()->count() > 0) {
            return response()->json([
                'code' => 422,
                'message' => '该节点下还有子节点，请先删除子节点',
            ], 422);
        }

        $orgName = $org->name;
        $org->delete();

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'organization',
            'action_type' => 'delete',
            'target_type' => 'organization',
            'target_id' => $id,
            'target_name' => $orgName,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '组织节点已删除',
        ]);
    }

    /**
     * 添加人员到组织
     * POST /api/organizations/{id}/users
     */
    public function addUsers(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            return response()->json(['code' => 403, 'message' => '仅超级管理员可以管理组织人员'], 403);
        }

        $org = Organization::findOrFail($id);

        $request->validate([
            'user_ids' => ['required', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'role_in_org' => ['nullable', 'string', 'max:50'],
        ]);

        $roleInOrg = $request->input('role_in_org');
        $now = now();

        $syncData = [];
        foreach ($request->input('user_ids') as $userId) {
            $syncData[$userId] = [
                'role_in_org' => $roleInOrg,
                'is_primary' => false,
                'assigned_at' => $now,
            ];
        }

        // 使用 syncWithoutDetaching 保留已有的关联
        $org->users()->syncWithoutDetaching($syncData);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'organization',
            'action_type' => 'add_users',
            'target_type' => 'organization',
            'target_id' => $org->id,
            'target_name' => $org->name,
            'detail' => ['user_ids' => $request->input('user_ids')],
        ]);

        return response()->json([
            'code' => 200,
            'message' => '人员添加成功',
        ]);
    }
}
