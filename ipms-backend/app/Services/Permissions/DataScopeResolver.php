<?php

namespace App\Services\Permissions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 数据范围解析器骨架（v1.8 §2.2：层级覆盖，同层并集；账户 > 角色 > 组织 > 类型默认）。
 *
 * Phase A：消费方仍走现有 4 个 Scope（builtin 默认）；本解析器只负责把
 * data_scopes 配置解析成标准描述符，供 Phase B 逐资源切换。
 * 返回描述符：['kind' => 'all'|'own'|'org_subtree'|'projects'|'none'|'builtin', ...]
 */
final class DataScopeResolver
{
    private const LAYERS = ['user', 'role', 'organization'];

    /** 组织候选集（冻结）：用户直接所属且 active 的组织，不含祖先 */
    public function orgIdsFor(User $user): array
    {
        return $user->organizations()
            ->where('organizations.is_active', true)
            ->pluck('organizations.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function resolve(User $user, string $module): array
    {
        $rules = $this->rulesFor($user, $module);

        foreach (self::LAYERS as $layer) {
            $layerRules = $rules->where('subject_kind', $layer);
            if ($layerRules->isEmpty()) {
                continue;
            }
            return $this->union($layerRules);
        }

        // 无配置：现有按用户类型硬编码的 Scope 生效
        return ['kind' => 'builtin'];
    }

    /** 同层多主体规则取并集 */
    private function union($rules): array
    {
        if ($rules->contains('scope_type', 'all')) {
            return ['kind' => 'all'];
        }

        $orgAnchors = [];
        $projectIds = [];
        $own = false;
        foreach ($rules as $rule) {
            switch ($rule->scope_type) {
                case 'own':
                    $own = true;
                    break;
                case 'org_subtree':
                    $orgAnchors[] = (int) $rule->org_id;
                    break;
                case 'projects':
                    foreach ($rule->project_ids as $projectId) {
                        $projectIds[] = (int) $projectId;
                    }
                    break;
                case 'none':
                    break;
            }
        }

        // 并集为空集（全是 none）→ none
        if (! $own && $orgAnchors === [] && $projectIds === []) {
            return ['kind' => 'none'];
        }

        return [
            'kind' => 'union',
            'own' => $own,
            'org_anchor_ids' => array_values(array_unique($orgAnchors)),
            'project_ids' => array_values(array_unique($projectIds)),
        ];
    }

    private function rulesFor(User $user, string $module)
    {
        $roleIds = $user->roles()->pluck('roles.id');
        $orgIds = $this->orgIdsFor($user);

        $rules = DB::table('data_scopes')
            ->where('module', $module)
            ->where(function ($query) use ($user, $roleIds, $orgIds): void {
                $query->where('user_id', $user->id)
                    ->orWhereIn('role_id', $roleIds)
                    ->orWhereIn('organization_id', $orgIds);
            })
            ->get();

        $projectDetails = DB::table('data_scope_projects')
            ->whereIn('data_scope_id', $rules->pluck('id'))
            ->get()
            ->groupBy('data_scope_id');

        return $rules->map(function ($rule) use ($projectDetails) {
            return (object) [
                'subject_kind' => $rule->user_id !== null
                    ? 'user'
                    : ($rule->role_id !== null ? 'role' : 'organization'),
                'scope_type' => $rule->scope_type,
                'org_id' => $rule->org_id,
                'project_ids' => ($projectDetails[$rule->id] ?? collect())->pluck('project_id')->all(),
            ];
        });
    }
}
