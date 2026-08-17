<?php

namespace App\Policies;

use App\Enums\UserType;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * 查看任务详情（行级权限检查）
     */
    public function view(User $user, Task $task): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($user->user_type) {
            UserType::INTERNAL->value => $task->project()
                ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
                ->exists(),
            UserType::SUPPLIER->value => $task->project()
                ->whereIn('supplier_org_id', $user->getSupplierDescendantOrgIds())
                ->exists(),
            default => false,
        };
    }

    /**
     * 创建任务
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('task.create');
    }

    /**
     * 编辑任务
     */
    public function update(User $user, Task $task): bool
    {
        if (!$user->hasPermission('task.edit')) {
            return false;
        }
        return $this->view($user, $task);
    }

    /**
     * 认领任务（供应商开发/测试人员）
     */
    public function claim(User $user, Task $task): bool
    {
        return $user->hasPermission('task.claim');
    }

    /**
     * 变更任务状态
     */
    public function transition(User $user, Task $task): bool
    {
        return $user->hasPermission('task.transition');
    }
}
