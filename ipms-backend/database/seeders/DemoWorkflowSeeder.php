<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

class DemoWorkflowSeeder extends Seeder
{
    private const ROLE_USERS = [
        'super_admin' => ['username' => 'demo.super_admin', 'display_name' => '演示超级管理员', 'user_type' => 1],
        'it_pm' => ['username' => 'demo.it_pm', 'display_name' => '演示 IT 项目经理', 'user_type' => 1],
        'it_member' => ['username' => 'demo.it_member', 'display_name' => '演示 IT 项目成员', 'user_type' => 1],
        'supplier_pm' => ['username' => 'demo.supplier_pm', 'display_name' => '演示供应商项目经理', 'user_type' => 2],
        'supplier_dev' => ['username' => 'demo.supplier_dev', 'display_name' => '演示供应商开发', 'user_type' => 2],
        'supplier_tester' => ['username' => 'demo.supplier_tester', 'display_name' => '演示供应商测试', 'user_type' => 2],
        'requester' => ['username' => 'demo.requester', 'display_name' => '演示需求提出人', 'user_type' => 3],
    ];

    private const ORGANIZATIONS = [
        1 => ['name' => '信息化部门', 'description' => '内部 IT 团队根节点'],
        2 => ['name' => '供应商', 'description' => '供应商团队根节点'],
        3 => ['name' => '公司', 'description' => '系统用户组织根节点'],
    ];

    public function run(): void
    {
        $password = config('ipms.demo_password');

        if (! is_string($password) || trim($password) === '') {
            throw new LogicException('IPMS_DEMO_PASSWORD is required to seed demo workflow data.');
        }

        DB::transaction(function () use ($password): void {
            $roleIds = $this->roleIds();
            $organizationIds = $this->seedOrganizations();
            $userIds = $this->seedUsers($password, $roleIds, $organizationIds);
            $this->seedProjects($userIds, $organizationIds[2]);
        });
    }

    private function roleIds(): array
    {
        $roleIds = DB::table('roles')
            ->whereIn('code', array_keys(self::ROLE_USERS))
            ->pluck('id', 'code')
            ->all();

        $missingRoles = array_diff(array_keys(self::ROLE_USERS), array_keys($roleIds));
        if ($missingRoles !== []) {
            throw new LogicException('Missing required demo roles: '.implode(', ', $missingRoles));
        }

        return $roleIds;
    }

    private function seedOrganizations(): array
    {
        $organizationIds = [];

        foreach (self::ORGANIZATIONS as $type => $organization) {
            DB::table('organizations')->updateOrInsert(
                ['name' => $organization['name'], 'org_type' => $type],
                [
                    'parent_id' => null,
                    'description' => $organization['description'],
                    'is_active' => true,
                    'updated_at' => now(),
                ],
            );

            $organizationIds[$type] = DB::table('organizations')
                ->where('name', $organization['name'])
                ->where('org_type', $type)
                ->value('id');
        }

        return $organizationIds;
    }

    private function seedUsers(string $password, array $roleIds, array $organizationIds): array
    {
        $passwordHash = Hash::make($password);
        $userIds = [];

        foreach (self::ROLE_USERS as $roleCode => $user) {
            DB::table('users')->updateOrInsert(
                ['username' => $user['username']],
                [
                    'password' => $passwordHash,
                    'user_type' => $user['user_type'],
                    'display_name' => $user['display_name'],
                    'first_name' => '',
                    'last_name' => '',
                    'email' => $user['username'].'@ipms.local',
                    'is_active' => true,
                    'is_staff' => $roleCode === 'super_admin',
                    'must_change_password' => false,
                    'is_disabled' => false,
                    'created_by_id' => null,
                    'updated_at' => now(),
                ],
            );

            $userId = DB::table('users')->where('username', $user['username'])->value('id');
            $userIds[$roleCode] = $userId;

            DB::table('role_user')->updateOrInsert(
                ['user_id' => $userId, 'role_id' => $roleIds[$roleCode]],
                ['assigned_by_id' => null, 'assigned_at' => now()],
            );

            $organizationId = $organizationIds[$user['user_type']];
            DB::table('organization_user')->updateOrInsert(
                ['user_id' => $userId, 'organization_id' => $organizationId],
                ['role_in_org' => $roleCode, 'is_primary' => true, 'assigned_at' => now()],
            );

            DB::table('notification_configs')->updateOrInsert(
                ['user_id' => $userId],
                ['remind_enabled' => true, 'remind_days_before' => 1, 'updated_at' => now()],
            );
        }

        return $userIds;
    }

    private function seedProjects(array $userIds, int $supplierOrganizationId): void
    {
        $projects = [
            ['name' => '核心业务平台', 'system_type' => 1, 'description' => '核心业务流程演示项目'],
            ['name' => '协同办公平台', 'system_type' => 2, 'description' => '内部协同流程演示项目'],
        ];

        foreach ($projects as $project) {
            DB::table('projects')->updateOrInsert(
                ['name' => $project['name']],
                [
                    'system_type' => $project['system_type'],
                    'description' => $project['description'],
                    'status' => 1,
                    'manager_id' => $userIds['it_pm'],
                    'supplier_org_id' => $supplierOrganizationId,
                    'created_by_id' => $userIds['super_admin'],
                    'updated_at' => now(),
                ],
            );

            $projectId = DB::table('projects')->where('name', $project['name'])->value('id');
            $members = [
                'it_pm' => 'pm',
                'it_member' => 'member',
                'supplier_pm' => 'pm',
                'supplier_dev' => 'member',
                'supplier_tester' => 'member',
            ];

            foreach ($members as $roleCode => $projectRole) {
                DB::table('project_members')->updateOrInsert(
                    ['project_id' => $projectId, 'user_id' => $userIds[$roleCode]],
                    [
                        'role_in_project' => $projectRole,
                        'assigned_by_id' => $userIds['super_admin'],
                        'assigned_at' => now(),
                    ],
                );
            }
        }
    }
}
