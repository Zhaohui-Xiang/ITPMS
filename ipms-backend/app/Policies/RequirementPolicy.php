<?php

namespace App\Policies;

use App\Enums\UserType;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\User;

class RequirementPolicy
{
    /**
     * Determine if the user can view the requirement (row-level check).
     */
    public function view(User $user, Requirement $requirement): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($user->user_type) {
            UserType::INTERNAL->value => $requirement->projects()
                ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
                ->exists(),
            UserType::SUPPLIER->value => $requirement->projects()
                ->whereIn('supplier_org_id', $user->getSupplierDescendantOrgIds())
                ->exists(),
            UserType::SYSTEM_USER->value => $requirement->submitter_id === $user->id,
            default => false,
        };
    }

    /**
     * Determine if the user can create requirements.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('requirement.create');
    }

    /**
     * Determine if the user can update the requirement.
     */
    public function update(User $user, Requirement $requirement): bool
    {
        if (! $user->hasPermission('requirement.edit')) {
            return false;
        }

        return $this->view($user, $requirement);
    }

    /**
     * Determine if the user can delete the requirement.
     */
    public function delete(User $user, Requirement $requirement): bool
    {
        if (! $user->hasPermission('requirement.delete')) {
            return false;
        }

        return $this->view($user, $requirement);
    }

    /**
     * Determine if the user can approve/reject requirements.
     */
    public function approve(User $user, Requirement $requirement): bool
    {
        if (! $user->hasPermission('requirement.approve')) {
            return false;
        }

        return $this->view($user, $requirement);
    }

    /**
     * Determine if the user can assign requirements.
     */
    public function assign(User $user, Requirement $requirement): bool
    {
        if (! $user->hasPermission('requirement.assign')) {
            return false;
        }

        return $this->view($user, $requirement);
    }

    /**
     * Determine if the user can transition requirement status.
     */
    public function transition(User $user, Requirement $requirement): bool
    {
        if (! $user->hasPermission('requirement.transition')) {
            return false;
        }

        return $this->view($user, $requirement);
    }

    public function transitionProject(
        User $user,
        Requirement $requirement,
        Project $project,
    ): bool {
        if (! $user->hasPermission('requirement.transition')) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($user->user_type) {
            UserType::INTERNAL->value => $project->members()
                ->where('user_id', $user->id)
                ->exists(),
            UserType::SUPPLIER->value => in_array(
                $project->supplier_org_id,
                $user->getSupplierDescendantOrgIds(),
                true,
            ),
            UserType::SYSTEM_USER->value => $requirement->submitter_id === $user->id,
            default => false,
        };
    }
}
