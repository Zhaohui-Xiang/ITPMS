<?php

namespace App\Scopes;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * ProjectScope -- 项目数据隔离 Query Scope
 *
 * 根据当前登录用户的角色过滤可见项目：
 * - 超级管理员：全局所有项目
 * - 内部 IT 项目经理/成员：按 project_members 分配范围
 * - 供应商团队：按供应商组织树范围（supplier_org_id 在子树内）
 * - 系统用户：仅可见自己提交需求所关联的项目
 */
class ProjectScope
{
    /**
     * Apply scope to filter projects visible to the given user.
     */
    public static function apply(Builder $query, User $user): Builder
    {
        // 超级管理员查看所有项目
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return match ($user->user_type) {
            UserType::INTERNAL->value => static::forInternal($query, $user),
            UserType::SUPPLIER->value => static::forSupplier($query, $user),
            UserType::SYSTEM_USER->value => static::forSystemUser($query, $user),
            default => $query->whereRaw('1 = 0'), // 未知用户类型，返回空
        };
    }

    /**
     * 内部 IT 用户：查看 project_members 中分配的项目
     */
    private static function forInternal(Builder $query, User $user): Builder
    {
        return $query->where(function ($projectQuery) use ($user) {
            $projectQuery
                ->where('manager_id', $user->id)
                ->orWhereHas('members', function ($memberQuery) use ($user) {
                    $memberQuery->where('user_id', $user->id);
                });
        });
    }

    /**
     * 供应商用户：查看供应商组织子树下的项目
     */
    private static function forSupplier(Builder $query, User $user): Builder
    {
        $orgIds = $user->getSupplierDescendantOrgIds();
        if (empty($orgIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('supplier_org_id', $orgIds);
    }

    /**
     * 系统用户：查看自己提交需求所关联的项目
     */
    private static function forSystemUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('requirements', function ($q) use ($user) {
            $q->where('submitter_id', $user->id);
        });
    }
}
