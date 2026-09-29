<?php

namespace App\Services\Permissions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 功能权限解析器（v1.8 §2.2 真值表）：
 * - 有效超管恒 allow（isEffectiveSuperAdmin）
 * - 任一主体（账户/角色/直接且 active 的组织）存在未过期 deny → deny
 * - 否则任一主体存在未过期 allow → allow
 * - 无规则 → 用户类型内置默认（现有 permission_role 角色矩阵，行为与历史完全一致）
 */
final class PermissionResolver
{
    public function __construct(
        private readonly PermissionCache $cache,
    ) {}

    public function allows(User $user, string $code): bool
    {
        // Phase A 默认（§2.8 构建级激活前）：与历史实现逐字等价，零额外查询
        if (! config('authorization.dual_source', false)) {
            return cache()->remember("user_{$user->id}_perm_{$code}", 300, function () use ($user, $code) {
                return $user->roles()
                    ->whereHas('permissions', fn ($q) => $q->where('code', $code))
                    ->exists();
            });
        }

        // 双读路径（激活后）：有效超管恒 allow
        if ($user->isEffectiveSuperAdmin()) {
            return true;
        }

        return $this->cache->remember(
            'permission',
            'user:'.$user->id,
            hash('sha256', $code),
            fn (): bool => $this->compute($user, $code),
            $this->nearestExpiry($user),
        );
    }

    private function compute(User $user, string $code): bool
    {
        $rules = $this->rulesFor($user, $code);

        $active = $rules->filter(
            fn ($rule) => $rule->expires_at === null || $rule->expires_at->isFuture(),
        );
        if ($active->contains('effect', 'deny')) {
            return false;
        }
        if ($active->contains('effect', 'allow')) {
            return true;
        }

        // 无规则：用户类型内置默认（permission_role 现状）
        return $user->roles()
            ->whereHas('permissions', fn ($q) => $q->where('code', $code))
            ->exists();
    }

    /** 用户全部主体（账户/角色/直接且 active 的组织）在该权限码上的规则 */
    private function rulesFor(User $user, string $code)
    {
        $roleIds = $user->roles()->pluck('roles.id');
        $orgIds = $user->organizations()->where('organizations.is_active', true)
            ->pluck('organizations.id');

        return DB::table('permission_rules')
            ->join('permissions', 'permissions.id', '=', 'permission_rules.permission_id')
            ->where('permissions.code', $code)
            ->where(function ($query) use ($user, $roleIds, $orgIds): void {
                $query->where('permission_rules.user_id', $user->id)
                    ->orWhereIn('permission_rules.role_id', $roleIds)
                    ->orWhereIn('permission_rules.organization_id', $orgIds);
            })
            ->select('permission_rules.effect', 'permission_rules.expires_at')
            ->get()
            ->map(fn ($row) => (object) [
                'effect' => $row->effect,
                'expires_at' => $row->expires_at === null ? null : \Illuminate\Support\Carbon::parse($row->expires_at),
            ]);
    }

    /** 该用户相关规则的最近到期时间（TTL 截断输入） */
    private function nearestExpiry(User $user): ?\Carbon\CarbonInterface
    {
        $roleIds = $user->roles()->pluck('roles.id');
        $orgIds = $user->organizations()->where('organizations.is_active', true)
            ->pluck('organizations.id');

        $nearest = DB::table('permission_rules')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where(function ($query) use ($user, $roleIds, $orgIds): void {
                $query->where('permission_rules.user_id', $user->id)
                    ->orWhereIn('permission_rules.role_id', $roleIds)
                    ->orWhereIn('permission_rules.organization_id', $orgIds);
            })
            ->min('expires_at');

        return $nearest === null ? null : \Illuminate\Support\Carbon::parse($nearest);
    }
}
