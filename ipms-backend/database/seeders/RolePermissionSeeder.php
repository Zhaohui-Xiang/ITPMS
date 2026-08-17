<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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
            'defect.create', 'defect.edit', 'defect.fix', 'defect.view',
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
            'defect.create', 'defect.edit', 'defect.view',
            'document.upload', 'document.download', 'document.view',
            'audit.view_scoped',
        ]);

        // Requester (system user)
        $this->assignPermissions($roles['requester'], [
            'requirement.create', 'requirement.edit', 'requirement.view', 'requirement.transition',
            'defect.create', 'defect.view',
            'audit.view_scoped',
        ]);
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
