<?php

namespace App\Policies;

use App\Enums\ProjectVersionStatus;
use App\Enums\UserType;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\User;

class ProjectVersionPolicy
{
    public function view(User $user, ProjectVersion $version): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('project_version.view')
            && app(ProjectPolicy::class)->view($user, $version->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->canManage($user, $project, 'project_version.create');
    }

    public function update(User $user, ProjectVersion $version): bool
    {
        return $this->canManage($user, $version->project, 'project_version.edit');
    }

    public function transition(User $user, ProjectVersion $version): bool
    {
        return $this->canManage($user, $version->project, 'project_version.transition');
    }

    public function release(User $user, ProjectVersion $version): bool
    {
        return $this->canManage($user, $version->project, 'project_version.release');
    }

    public function forceRelease(User $user, ProjectVersion $version): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, ProjectVersion $version): bool
    {
        return $this->canManage($user, $version->project, 'project_version.edit')
            && $version->status === ProjectVersionStatus::DRAFT
            && $version->requirementLinks()->doesntExist()
            && $version->histories()->doesntExist();
    }

    private function canManage(User $user, Project $project, string $permission): bool
    {
        return ! $user->isSuperAdmin()
            && $user->user_type === UserType::INTERNAL->value
            && $user->roles()->where('code', 'it_pm')->exists()
            && $user->hasPermission($permission)
            && $project->manager_id === $user->id;
    }
}
