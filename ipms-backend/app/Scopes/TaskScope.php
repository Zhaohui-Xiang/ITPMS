<?php

namespace App\Scopes;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * TaskScope -- 任务数据隔离 Query Scope
 *
 * 根据当前登录用户的角色过滤可见任务：
 * - 超级管理员：全局所有任务
 * - 内部 IT 项目经理/成员：按 project_members 分配的项目范围
 * - 供应商团队：按供应商组织树范围
 * - 系统用户：无权限查看任务
 */
class TaskScope
{
    /**
     * Apply scope to filter tasks visible to the given user.
     */
    public static function apply(Builder $query, User $user): Builder
    {
        // 超级管理员查看所有任务
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return match ($user->user_type) {
            UserType::INTERNAL->value => static::forInternal($query, $user),
            UserType::SUPPLIER->value => static::forSupplier($query, $user),
            UserType::SYSTEM_USER->value => $query->whereRaw('1 = 0'), // 系统用户无任务权限
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * 内部 IT 用户：查看分配项目下的任务
     */
    private static function forInternal(Builder $query, User $user): Builder
    {
        return $query->whereHas('project', function ($q) use ($user) {
            $q->whereHas('members', function ($sq) use ($user) {
                $sq->where('user_id', $user->id);
            });
        });
    }

    /**
     * 供应商用户：查看供应商项目下的任务
     */
    private static function forSupplier(Builder $query, User $user): Builder
    {
        $orgIds = $user->getSupplierDescendantOrgIds();
        if (empty($orgIds)) {
            return $query->whereRaw('1 = 0');
        }
        return $query->whereHas('project', function ($q) use ($orgIds) {
            $q->whereIn('supplier_org_id', $orgIds);
        });
    }
}
