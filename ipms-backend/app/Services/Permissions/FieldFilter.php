<?php

namespace App\Services\Permissions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 字段权限过滤器骨架（v1.8 §2.2：全局最严格 hidden > masked > readonly > editable；
 * 无配置 = editable）。Phase A 仅解析描述符，消费方在 Phase B 随读/写闭包接入。
 */
final class FieldFilter
{
    private const SEVERITY = [
        'editable' => 0,
        'readonly' => 1,
        'masked' => 2,
        'hidden' => 3,
    ];

    public function resolve(User $user, string $resource, string $field): string
    {
        $roleIds = $user->roles()->pluck('roles.id');
        $orgIds = $user->organizations()
            ->where('organizations.is_active', true)
            ->pluck('organizations.id');

        $effects = DB::table('field_permissions')
            ->where('resource', $resource)
            ->where('field', $field)
            ->where(function ($query) use ($user, $roleIds, $orgIds): void {
                $query->where('user_id', $user->id)
                    ->orWhereIn('role_id', $roleIds)
                    ->orWhereIn('organization_id', $orgIds);
            })
            ->pluck('effect');

        if ($effects->isEmpty()) {
            return 'editable';
        }

        return $effects->sortByDesc(fn ($effect) => self::SEVERITY[$effect] ?? 0)->first();
    }
}
