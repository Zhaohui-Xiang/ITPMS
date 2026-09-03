<?php

namespace App\Policies;

use App\Enums\UserType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        if ($user->user_type === UserType::SYSTEM_USER->value) {
            return false;
        }

        return app(ProjectPolicy::class)->view($user, $task->project);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin()
            || $user->hasPermission('task.create');
    }

    public function createForProject(User $user, Project $project): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $user->hasPermission('task.create')
            || ! app(ProjectPolicy::class)->view($user, $project)) {
            return false;
        }

        return $this->isManagedInternal($user, $project)
            || $this->isInternalMember($user, $project)
            || $this->isSupplierPm($user, $project);
    }

    public function update(User $user, Task $task): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $user->hasPermission('task.edit') || ! $this->view($user, $task)) {
            return false;
        }

        return $this->isManagedInternal($user, $task->project)
            || $this->isInternalMember($user, $task->project)
            || $this->isSupplierPm($user, $task->project);
    }

    public function assign(User $user, Task $task): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('task.assign')
            && ($this->isManagedInternal($user, $task->project)
                || $this->isSupplierPm($user, $task->project));
    }

    public function claim(User $user, Task $task): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->hasRole($user, 'supplier_dev')
            && $user->hasPermission('task.claim')
            && $this->view($user, $task);
    }

    public function transition(User $user, Task $task): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($this->isManagedInternal($user, $task->project)
            && $user->hasPermission('task.assign')) {
            return true;
        }

        if ($this->isSupplierPm($user, $task->project)
            && $user->hasPermission('task.assign')) {
            return true;
        }

        return $this->hasRole($user, 'supplier_dev')
            && $user->hasPermission('task.update_status')
            && $task->assignee_id === $user->id
            && $this->view($user, $task);
    }

    public function hold(User $user, Task $task): bool
    {
        return $this->transition($user, $task);
    }

    private function isManagedInternal(User $user, Project $project): bool
    {
        return $user->user_type === UserType::INTERNAL->value
            && $this->hasRole($user, 'it_pm')
            && $project->manager_id === $user->id;
    }

    private function isInternalMember(User $user, Project $project): bool
    {
        return $user->user_type === UserType::INTERNAL->value
            && $this->hasRole($user, 'it_member')
            && $project->members()->where('user_id', $user->id)->exists();
    }

    private function isSupplierPm(User $user, Project $project): bool
    {
        return $user->user_type === UserType::SUPPLIER->value
            && $this->hasRole($user, 'supplier_pm')
            && in_array(
                $project->supplier_org_id,
                $user->getSupplierDescendantOrgIds(),
                true,
            );
    }

    private function hasRole(User $user, string $role): bool
    {
        return $user->roles()->where('code', $role)->exists();
    }
}
