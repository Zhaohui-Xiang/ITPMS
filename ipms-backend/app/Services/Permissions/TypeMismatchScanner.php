<?php

namespace App\Services\Permissions;

use Illuminate\Support\Facades\DB;

/**
 * 主体类型错配扫描器（ScanTypeMismatches 命令与 ipms:perm-preflight 共用）。
 */
final class TypeMismatchScanner
{
    public function roleUserMismatches()
    {
        return DB::table('role_user')
            ->join('users', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereColumn('users.user_type', '<>', 'roles.user_type')
            ->select(
                'role_user.id',
                'users.username',
                'users.user_type',
                'roles.code as role_code',
                'roles.user_type as role_type',
            )
            ->orderBy('role_user.id')
            ->get();
    }

    public function organizationUserMismatches()
    {
        return DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->join('organizations', 'organizations.id', '=', 'organization_user.organization_id')
            ->whereColumn('users.user_type', '<>', 'organizations.org_type')
            ->select(
                'organization_user.id',
                'users.username',
                'users.user_type',
                'organizations.name as org_name',
                'organizations.org_type',
            )
            ->orderBy('organization_user.id')
            ->get();
    }

    public function count(): int
    {
        return $this->roleUserMismatches()->count() + $this->organizationUserMismatches()->count();
    }
}
