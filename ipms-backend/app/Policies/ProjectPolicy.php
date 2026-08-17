<?php

namespace App\Policies;

use App\Enums\UserType;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * 查看项目详情（行级权限检查）
     */
    public function view(User $user, Project $project): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($user->user_type) {
            UserType::INTERNAL->value => $project->members()
                ->where('user_id', $user->id)
                ->exists(),
            UserType::SUPPLIER->value => in_array(
                $project->supplier_org_id,
                $user->getSupplierDescendantOrgIds()
            ),
            UserType::SYSTEM_USER->value => $project->requirements()
                ->where('submitter_id', $user->id)
                ->exists(),
            default => false,
        };
    }

    /**
     * 创建项目（仅超管）
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * 编辑项目（仅超管）
     */
    public function update(User $user, Project $project): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * 删除项目（仅超管）
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->isSuperAdmin();
    }
}
