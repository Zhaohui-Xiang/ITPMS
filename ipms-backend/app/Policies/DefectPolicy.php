<?php

namespace App\Policies;

use App\Enums\UserType;
use App\Models\Defect;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\User;

class DefectPolicy
{
    public function view(User $user, Defect $defect): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($user->user_type) {
            UserType::INTERNAL->value => $defect->project->manager_id === $user->id
                || $defect->project->members()->where('user_id', $user->id)->exists(),
            UserType::SUPPLIER->value => in_array(
                $defect->project->supplier_org_id,
                $user->getSupplierDescendantOrgIds(),
                true,
            ),
            UserType::SYSTEM_USER->value => $defect->requirement->submitter_id === $user->id,
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('defect.create');
    }

    public function createForProject(User $user, Project $project): bool
    {
        return $user->hasPermission('defect.create')
            && app(ProjectPolicy::class)->view($user, $project);
    }

    public function createForRequirement(
        User $user,
        Requirement $requirement,
        Project $project,
    ): bool {
        if (! $this->createForProject($user, $project)) {
            return false;
        }

        if ($user->user_type === UserType::SYSTEM_USER->value) {
            return $requirement->submitter_id === $user->id;
        }

        // The workflow service validates the requirement-project relation.
        return true;
    }

    public function update(User $user, Defect $defect): bool
    {
        return ($user->isSuperAdmin() || $user->hasPermission('defect.edit'))
            && $this->view($user, $defect);
    }

    public function confirm(User $user, Defect $defect): bool
    {
        return $user->isSuperAdmin()
            || (
                $this->hasRole($user, 'it_pm')
                && $user->hasPermission('defect.confirm')
                && $defect->project->manager_id === $user->id
            );
    }

    public function assign(User $user, Defect $defect): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($this->hasRole($user, 'it_pm')
            && $user->hasPermission('defect.assign')
            && $defect->project->manager_id === $user->id) {
            return true;
        }

        return $this->hasRole($user, 'supplier_pm')
            && $user->hasPermission('defect.assign')
            && in_array(
                $defect->project->supplier_org_id,
                $user->getSupplierDescendantOrgIds(),
                true,
            );
    }

    public function resolve(User $user, Defect $defect): bool
    {
        return $this->hasRole($user, 'supplier_dev')
            && $user->hasPermission('defect.fix')
            && $defect->assignee_id === $user->id
            && $this->view($user, $defect);
    }

    public function verify(User $user, Defect $defect): bool
    {
        return $user->isSuperAdmin()
            || (
                $this->hasRole($user, 'supplier_tester')
                && $user->hasPermission('defect.retest')
                && $defect->project->members()
                    ->where('user_id', $user->id)
                    ->exists()
                && $this->view($user, $defect)
            );
    }

    public function reopen(User $user, Defect $defect): bool
    {
        return $this->verify($user, $defect);
    }

    private function hasRole(User $user, string $role): bool
    {
        return $user->roles()->where('code', $role)->exists();
    }
}
