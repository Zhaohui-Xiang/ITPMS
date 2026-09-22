<?php

namespace App\Services;

use App\Models\Requirement;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RequirementExecutionOwners
{
    public function eligible(User $user, Requirement $requirement): bool
    {
        return $user->is_active && ! $user->is_disabled
            && in_array($user->user_type, [1, 2], true)
            && $user->roles()->whereIn('code', ['it_pm', 'it_member', 'supplier_pm', 'supplier_dev'])->exists()
            && $requirement->projects->isNotEmpty()
            && $requirement->projects->every(fn ($project): bool => Gate::forUser($user)->allows('view', $project));
    }

    public function options(Requirement $requirement): array
    {
        return User::query()->where('is_active', true)->where('is_disabled', false)
            ->whereHas('roles', fn ($roles) => $roles->whereIn('code', ['it_pm', 'it_member', 'supplier_pm', 'supplier_dev']))
            ->orderBy('display_name')->orderBy('id')->get()
            ->filter(fn (User $user): bool => $this->eligible($user, $requirement))
            ->map(fn (User $user): array => ['id' => $user->id, 'display_name' => $user->display_name])
            ->values()->all();
    }
}
