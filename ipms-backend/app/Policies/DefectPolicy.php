<?php

namespace App\Policies;

use App\Enums\UserType;
use App\Models\Defect;
use App\Models\User;

class DefectPolicy
{
    /**
     * 查看缺陷详情（行级权限检查）
     */
    public function view(User $user, Defect $defect): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($user->user_type) {
            UserType::INTERNAL->value => $defect->project()
                ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
                ->exists(),
            UserType::SUPPLIER->value => $defect->project()
                ->whereIn('supplier_org_id', $user->getSupplierDescendantOrgIds())
                ->exists(),
            UserType::SYSTEM_USER->value => $defect->requirement()
                ->where('submitter_id', $user->id)
                ->exists(),
            default => false,
        };
    }

    /**
     * 提交缺陷
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('defect.create');
    }

    /**
     * 编辑缺陷
     */
    public function update(User $user, Defect $defect): bool
    {
        if (!$user->hasPermission('defect.edit')) {
            return false;
        }
        return $this->view($user, $defect);
    }

    /**
     * 确认缺陷
     */
    public function confirm(User $user, Defect $defect): bool
    {
        return $user->hasPermission('defect.confirm');
    }

    /**
     * 指派修复
     */
    public function assign(User $user, Defect $defect): bool
    {
        return $user->hasPermission('defect.assign');
    }

    /**
     * 标记修复完成
     */
    public function resolve(User $user, Defect $defect): bool
    {
        return $user->hasPermission('defect.resolve');
    }

    /**
     * 复测
     */
    public function verify(User $user, Defect $defect): bool
    {
        return $user->hasPermission('defect.verify');
    }
}
