<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * 用户列表（超管）
     * GET /api/users
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // 权限过滤
        if ($user->isSuperAdmin()) {
            // 超管查看所有用户
            $query = User::with('roles:id,name,code')
                ->with('organizations:id,name,org_type');
        } elseif ($user->user_type === UserType::SUPPLIER->value && $user->hasPermission('user.view')) {
            // 供应商项目经理查看本团队用户
            $orgIds = $user->getSupplierDescendantOrgIds();
            $query = User::with('roles:id,name,code')
                ->whereHas('organizations', function ($q) use ($orgIds) {
                    $q->whereIn('organizations.id', $orgIds);
                });
        } else {
            return response()->json(['code' => 403, 'message' => '您无权查看用户列表'], 403);
        }

        // 按用户类型筛选
        if ($request->has('user_type')) {
            $query->where('user_type', $request->integer('user_type'));
        }

        // 按组织筛选
        if ($request->has('organization_id')) {
            $orgId = $request->integer('organization_id');
            $query->whereHas('organizations', function ($q) use ($orgId) {
                $q->where('organizations.id', $orgId);
            });
        }

        // 搜索
        if ($request->has('keyword')) {
            $search = $request->input('keyword');
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $pageSize = min(max($request->integer('page_size', 20), 1), 100);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($pageSize);

        return ApiResponse::paginated($paginator);
    }

    /**
     * 创建用户（超管/供应商项目经理）
     * POST /api/users
     */
    public function store(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        // 权限检查
        $canCreate = $currentUser->isSuperAdmin()
            || ($currentUser->user_type === UserType::SUPPLIER->value && $currentUser->hasPermission('user.create'));

        if (!$canCreate) {
            return response()->json(['code' => 403, 'message' => '您无权创建用户'], 403);
        }

        $request->validate([
            'username' => ['required', 'string', 'max:150', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'first_name' => ['nullable', 'string', 'max:150'],
            'last_name' => ['nullable', 'string', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:254', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'user_type' => ['required', 'integer', 'in:2,3'], // 仅可创建供应商用户和系统用户
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'organization_ids' => ['nullable', 'array'],
            'organization_ids.*' => ['integer', 'exists:organizations,id'],
        ]);

        $user = User::create([
            'username' => $request->input('username'),
            'password' => $request->input('password'),
            'first_name' => $request->input('first_name', ''),
            'last_name' => $request->input('last_name', ''),
            'display_name' => $request->input('display_name', $request->input('username')),
            'email' => $request->input('email', ''),
            'phone' => $request->input('phone'),
            'user_type' => $request->input('user_type'),
            'is_active' => true,
            'is_disabled' => false,
            'must_change_password' => true,
            'created_by_id' => $currentUser->id,
        ]);

        // 分配角色
        if ($request->has('role_ids')) {
            $user->roles()->sync($request->input('role_ids'));
        }

        // 分配组织
        if ($request->has('organization_ids')) {
            $syncData = [];
            foreach ($request->input('organization_ids') as $orgId) {
                $syncData[$orgId] = [
                    'role_in_org' => null,
                    'is_primary' => false,
                    'assigned_at' => now(),
                ];
            }
            $user->organizations()->sync($syncData);
        }

        // 操作日志
        AuditLogger::log($currentUser->id, [
            'user_name' => $currentUser->username,
            'user_display_name' => $currentUser->display_name,
            'user_type' => $currentUser->user_type,
            'module' => 'user',
            'action_type' => 'create',
            'target_type' => 'user',
            'target_id' => $user->id,
            'target_name' => $user->display_name,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '用户创建成功',
            'data' => $user->load('roles:id,name,code'),
        ], 201);
    }

    /**
     * 用户详情
     * GET /api/users/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        $user = User::with([
            'roles:id,name,code',
            'organizations:id,name,org_type',
        ])->findOrFail($id);

        // 权限检查
        if (!$currentUser->isSuperAdmin()
            && !($currentUser->user_type === UserType::SUPPLIER->value && $currentUser->hasPermission('user.view'))
            && $currentUser->id !== $user->id
        ) {
            return response()->json(['code' => 403, 'message' => '您无权查看该用户'], 403);
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $user,
        ]);
    }

    /**
     * 编辑用户
     * PUT /api/users/{id}
     */
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        $user = User::findOrFail($id);

        $user->update($request->only([
            'first_name', 'last_name', 'display_name', 'email', 'phone',
        ]));

        // 操作日志
        AuditLogger::log($currentUser->id, [
            'user_name' => $currentUser->username,
            'user_display_name' => $currentUser->display_name,
            'user_type' => $currentUser->user_type,
            'module' => 'user',
            'action_type' => 'update',
            'target_type' => 'user',
            'target_id' => $user->id,
            'target_name' => $user->display_name,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '用户信息更新成功',
            'data' => $user->fresh(['roles:id,name,code', 'organizations:id,name,org_type']),
        ]);
    }

    /**
     * 禁用用户
     * POST /api/users/{id}/disable
     */
    public function disable(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();

        if (!$currentUser->isSuperAdmin()) {
            return response()->json(['code' => 403, 'message' => '仅超级管理员可以禁用用户'], 403);
        }

        $user = User::findOrFail($id);

        // 不能禁用自己
        if ($currentUser->id === $user->id) {
            return response()->json(['code' => 422, 'message' => '不能禁用自己的账号'], 422);
        }

        $user->update(['is_disabled' => true]);

        // 操作日志
        AuditLogger::log($currentUser->id, [
            'user_name' => $currentUser->username,
            'user_display_name' => $currentUser->display_name,
            'user_type' => $currentUser->user_type,
            'module' => 'user',
            'action_type' => 'disable',
            'target_type' => 'user',
            'target_id' => $user->id,
            'target_name' => $user->display_name,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '用户已禁用',
        ]);
    }

    /**
     * 更新个人信息
     * PUT /api/settings/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'first_name' => ['nullable', 'string', 'max:150'],
            'last_name' => ['nullable', 'string', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:254', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $user->update($request->only([
            'first_name', 'last_name', 'display_name', 'email', 'phone',
        ]));

        return response()->json([
            'code' => 200,
            'message' => '个人信息更新成功',
            'data' => $user->fresh(),
        ]);
    }

    /**
     * 修改密码
     * PUT /api/settings/password
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);

        // 验证当前密码
        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'code' => 422,
                'message' => '验证失败',
                'errors' => ['current_password' => ['当前密码不正确']],
            ], 422);
        }

        $user->update([
            'password' => $request->input('new_password'),
            'must_change_password' => false,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '密码修改成功',
        ]);
    }
}
