<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Enums\UserType;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($user->user_type) {
            UserType::INTERNAL->value => $project->manager_id === $user->id
                || $project->members()->where('user_id', $user->id)->exists(),
            UserType::SUPPLIER->value => in_array(
                $project->supplier_org_id,
                $user->getSupplierDescendantOrgIds(),
                true,
            ),
            UserType::SYSTEM_USER->value => $project->requirements()
                ->where('submitter_id', $user->id)
                ->exists(),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->isSuperAdmin();
    }

    public function archive(User $user, Project $project): bool
    {
        return $user->isSuperAdmin()
            && $project->status !== ProjectStatus::ARCHIVED->value;
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->isSuperAdmin();
    }
}
