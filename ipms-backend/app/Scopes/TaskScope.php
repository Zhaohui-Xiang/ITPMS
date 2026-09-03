<?php

namespace App\Scopes;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * TaskScope -- task data isolation query scope.
 */
class TaskScope
{
    public static function apply(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return match ($user->user_type) {
            UserType::INTERNAL->value => static::forInternal($query, $user),
            UserType::SUPPLIER->value => static::forSupplier($query, $user),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private static function forInternal(Builder $query, User $user): Builder
    {
        return $query->whereHas('project', function (Builder $projectQuery) use ($user): void {
            $projectQuery
                ->where('manager_id', $user->id)
                ->orWhereHas('members', function (Builder $memberQuery) use ($user): void {
                    $memberQuery->where('user_id', $user->id);
                });
        });
    }

    private static function forSupplier(Builder $query, User $user): Builder
    {
        $orgIds = $user->getSupplierDescendantOrgIds();
        if ($orgIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'project',
            fn (Builder $projectQuery): Builder => $projectQuery
                ->whereIn('supplier_org_id', $orgIds),
        );
    }
}
