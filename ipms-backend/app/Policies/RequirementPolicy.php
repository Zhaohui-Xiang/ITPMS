<?php

namespace App\Policies;

use App\Enums\RequirementStatus;
use App\Enums\UserType;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\User;

class RequirementPolicy
{
    public function view(User $user, Requirement $requirement): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($user->user_type) {
            UserType::INTERNAL->value => $requirement->projects()
                ->where(function ($projectQuery) use ($user): void {
                    $projectQuery
                        ->where('manager_id', $user->id)
                        ->orWhereHas('members', fn ($memberQuery) => $memberQuery
                            ->where('user_id', $user->id));
                })
                ->exists(),
            UserType::SUPPLIER->value => $requirement->projects()
                ->whereIn('supplier_org_id', $user->getSupplierDescendantOrgIds())
                ->exists(),
            UserType::SYSTEM_USER->value => $requirement->submitter_id === $user->id,
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('requirement.create');
    }

    public function update(User $user, Requirement $requirement): bool
    {
        if ($requirement->isRejectedForResubmission()
            && $requirement->submitter_id !== $user->id) {
            return false;
        }

        return $user->hasPermission('requirement.edit')
            && $this->view($user, $requirement);
    }

    public function delete(User $user, Requirement $requirement): bool
    {
        return $user->hasPermission('requirement.delete')
            && $this->view($user, $requirement);
    }

    public function approve(User $user, Requirement $requirement): bool
    {
        return $user->hasPermission('requirement.approve')
            && $this->view($user, $requirement);
    }

    public function assign(User $user, Requirement $requirement): bool
    {
        return $user->hasPermission('requirement.assign')
            && $this->view($user, $requirement);
    }

    public function transition(User $user, Requirement $requirement): bool
    {
        return $user->hasPermission('requirement.transition')
            && $this->view($user, $requirement);
    }

    public function transitionProject(
        User $user,
        Requirement $requirement,
        Project $project,
    ): bool {
        if (! $user->hasPermission('requirement.transition')) {
            return false;
        }

        if (! $requirement->projects()->whereKey($project->id)->exists()) {
            return false;
        }

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
            UserType::SYSTEM_USER->value => $requirement->submitter_id === $user->id,
            default => false,
        };
    }

    public function createTask(
        User $user,
        Requirement $requirement,
        Project $project,
    ): bool {
        return $requirement->status !== RequirementStatus::PENDING_REVIEW->value
            && $requirement->projects()->whereKey($project->id)->exists()
            && app(TaskPolicy::class)->createForProject($user, $project);
    }
}
