<?php

namespace App\Scopes;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * DefectScope -- 缺陷数据隔离 Query Scope
 *
 * 根据当前登录用户的角色过滤可见缺陷：
 * - 超级管理员：全局所有缺陷
 * - 内部 IT 项目经理/成员：按 project_members 分配的项目范围
 * - 供应商团队：按供应商组织树范围
 * - 系统用户：仅可见自己提交的需求下的缺陷
 */
class DefectScope
{
    /**
     * Apply scope to filter defects visible to the given user.
     */
    public static function apply(Builder $query, User $user): Builder
    {
        // 超级管理员查看所有缺陷
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return match ($user->user_type) {
            UserType::INTERNAL->value => static::forInternal($query, $user),
            UserType::SUPPLIER->value => static::forSupplier($query, $user),
            UserType::SYSTEM_USER->value => static::forSystemUser($query, $user),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * 内部 IT 用户：查看分配项目下的缺陷
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
     * 供应商用户：查看供应商项目下的缺陷
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

    /**
     * 系统用户：仅查看自己提交需求下的缺陷
     */
    private static function forSystemUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('requirement', function ($q) use ($user) {
            $q->where('submitter_id', $user->id);
        });
    }
}
