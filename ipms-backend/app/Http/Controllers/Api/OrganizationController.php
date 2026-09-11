<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Models\Organization;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class OrganizationController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
    }

    public function index(Request $request): mixed
    {
        $this->authorizeAdmin($request);
        $values = $request->validate(['org_type' => ['nullable', 'integer', 'in:1,2,3']]);
        $query = Organization::query()->with('users:id,display_name,username,user_type')->orderBy('name');
        if (isset($values['org_type'])) {
            $query->where('org_type', $values['org_type']);
        }
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        $groups = $query->get()->groupBy(fn ($node) => $node->parent_id ?? 0);
        $build = function ($parent) use (&$build, $groups): array {
            return $groups->get($parent, collect())->map(fn ($node) => [
                ...$node->toArray(), 'children' => $build($node->id),
            ])->values()->all();
        };
        return ApiResponse::success($build(0));
    }

    public function children(Request $request, int $id): mixed
    {
        $this->authorizeAdmin($request);
        return ApiResponse::success(Organization::findOrFail($id)->children()
            ->with('users:id,display_name,username,user_type')->orderBy('name')->get());
    }

    public function store(Request $request): mixed
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'org_type' => ['required', 'integer', 'in:1,2,3'],
            'description' => ['nullable', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')->where('org_type', $request->integer('org_type'))],
        ]);
        $node = DB::transaction(function () use ($request, $data) {
            $node = Organization::create([...$data, 'is_active' => true]);
            $this->audit($request, $node, 1);
            return $node;
        });
        return response()->json(['code' => 200, 'message' => '组织已创建', 'data' => $node], 201);
    }

    public function update(Request $request, int $id): mixed
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        $node = DB::transaction(function () use ($request, $id, $data) {
            $node = Organization::query()->lockForUpdate()->findOrFail($id);
            $node->update($data);
            $this->audit($request, $node, 2);
            return $node;
        });
        return ApiResponse::success($node);
    }

    public function destroy(Request $request, int $id): mixed
    {
        $this->authorizeAdmin($request);
        return DB::transaction(function () use ($request, $id) {
            $node = Organization::query()->lockForUpdate()->findOrFail($id);
            if ($node->children()->exists() || $node->users()->exists()) {
                return ApiResponse::error('ORGANIZATION_NOT_EMPTY', '请先移除子组织和成员。', 422);
            }
            $this->audit($request, $node, 3);
            $node->delete();
            return ApiResponse::success(null);
        });
    }

    public function addUsers(Request $request, int $id): mixed
    {
        $this->authorizeAdmin($request);
        $node = Organization::findOrFail($id);
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('user_type', $node->org_type)],
            'role_in_org' => ['nullable', 'string', 'max:50'],
        ]);
        DB::transaction(function () use ($request, $node, $data): void {
            $memberships = [];
            foreach ($data['user_ids'] as $userId) {
                $memberships[$userId] = ['role_in_org' => $data['role_in_org'] ?? 'member', 'is_primary' => false, 'assigned_at' => now()];
            }
            $node->users()->syncWithoutDetaching($memberships);
            $this->audit($request, $node, 6, ['user_ids' => $data['user_ids']]);
        });
        foreach ($data['user_ids'] as $userId) {
            Cache::forget("user_{$userId}_supplier_org_ids");
        }
        return ApiResponse::success(null);
    }

    public function removeUser(Request $request, int $id, int $userId): mixed
    {
        $this->authorizeAdmin($request);
        DB::transaction(function () use ($request, $id, $userId): void {
            $node = Organization::findOrFail($id);
            $node->users()->where('users.id', $userId)->firstOrFail();
            $node->users()->detach($userId);
            $this->audit($request, $node, 6, ['removed_user_id' => $userId]);
        });
        Cache::forget("user_{$userId}_supplier_org_ids");
        return ApiResponse::success(null);
    }

    private function audit(Request $request, Organization $node, int $action, array $detail = []): void
    {
        $user = $request->user();
        AuditLogger::log($user->id, [
            'user_name' => $user->username, 'user_display_name' => $user->display_name, 'user_type' => $user->user_type,
            'module' => 7, 'action_type' => $action, 'target_type' => 'organization',
            'target_id' => $node->id, 'target_name' => $node->name, 'detail' => $detail,
        ]);
    }
}
