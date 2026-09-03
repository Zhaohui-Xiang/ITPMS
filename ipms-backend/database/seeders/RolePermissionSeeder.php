<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = DB::table('roles')->pluck('id', 'code');

        // Super admin: all permissions
        $allPermIds = DB::table('permissions')->pluck('id');
        $roleId = $roles['super_admin'];
        foreach ($allPermIds as $permId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permId,
            ]);
        }

        // IT PM
        $this->assignPermissions($roles['it_pm'], [
            'project.view', 'project.archive',
            'requirement.create', 'requirement.edit', 'requirement.view',
            'requirement.approve', 'requirement.assign', 'requirement.transition',
            'task.create', 'task.edit', 'task.assign', 'task.view',
            'defect.create', 'defect.confirm', 'defect.assign', 'defect.retest', 'defect.view',
            'document.upload', 'document.download', 'document.edit_api', 'document.view',
            'audit.view_scoped',
        ]);

        // IT Member
        $this->assignPermissions($roles['it_member'], [
            'project.view',
            'requirement.create', 'requirement.edit', 'requirement.view', 'requirement.transition',
            'task.create', 'task.edit', 'task.view',
            'defect.create', 'defect.edit', 'defect.view',
            'document.upload', 'document.download', 'document.edit_api', 'document.view',
            'audit.view_scoped',
        ]);

        // Supplier PM
        $this->assignPermissions($roles['supplier_pm'], [
            'project.view',
            'requirement.view', 'requirement.transition',
            'task.create', 'task.edit', 'task.assign', 'task.view',
            'defect.create', 'defect.edit', 'defect.assign', 'defect.fix', 'defect.view',
            'document.upload', 'document.download', 'document.edit_api', 'document.view',
            'audit.view_scoped',
        ]);

        // Supplier Dev
        $this->assignPermissions($roles['supplier_dev'], [
            'project.view',
            'requirement.view',
            'task.view', 'task.claim', 'task.update_status',
            'defect.view', 'defect.fix',
            'document.upload', 'document.download', 'document.view',
            'audit.view_scoped',
        ]);

        // Supplier Tester
        $this->assignPermissions($roles['supplier_tester'], [
            'project.view',
            'requirement.view',
            'task.view', 'task.claim', 'task.update_status',
            'defect.create', 'defect.edit', 'defect.retest', 'defect.view',
            'document.upload', 'document.download', 'document.view',
            'audit.view_scoped',
        ]);

        // Requester (system user)
        $this->assignPermissions($roles['requester'], [
            'requirement.create', 'requirement.edit', 'requirement.view', 'requirement.transition',
            'defect.create', 'defect.view',
            'audit.view_scoped',
        ]);

        $this->syncProjectVersionPermissions($roles);
    }

    private function syncProjectVersionPermissions(Collection $roles): void
    {
        $approvedCodes = [
            'super_admin' => [
                'project_version.view',
                'project_version.create',
                'project_version.edit',
                'project_version.transition',
                'project_version.release',
                'project_version.override',
            ],
            'it_pm' => [
                'project_version.view',
                'project_version.create',
                'project_version.edit',
                'project_version.transition',
                'project_version.release',
            ],
            'it_member' => ['project_version.view'],
            'supplier_pm' => ['project_version.view'],
            'supplier_dev' => ['project_version.view'],
            'supplier_tester' => ['project_version.view'],
            'requester' => ['project_version.view'],
        ];
        $permissionCodes = array_values(array_unique(array_merge(...array_values($approvedCodes))));
        $permissionIds = DB::table('permissions')
            ->whereIn('code', $permissionCodes)
            ->pluck('id', 'code');

        if ($permissionIds->count() !== count($permissionCodes)) {
            throw new LogicException('All project version permissions must exist before role synchronization.');
        }

        DB::transaction(function () use ($approvedCodes, $permissionIds, $roles): void {
            DB::table('permission_role')
                ->whereIn('permission_id', $permissionIds->values())
                ->delete();

            foreach ($approvedCodes as $roleCode => $codes) {
                foreach ($codes as $code) {
                    DB::table('permission_role')->insert([
                        'role_id' => $roles[$roleCode],
                        'permission_id' => $permissionIds[$code],
                    ]);
                }
            }
        });
    }

    private function assignPermissions(int $roleId, array $permCodes): void
    {
        $permIds = DB::table('permissions')->whereIn('code', $permCodes)->pluck('id');
        foreach ($permIds as $permId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permId,
            ]);
        }
    }
}
