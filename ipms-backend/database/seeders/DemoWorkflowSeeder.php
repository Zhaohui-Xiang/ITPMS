<?php

namespace Database\Seeders;

use App\Support\DemoSeedGuard;
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

    private const PROJECTS = [
        [
            'seed_marker' => 'ipms:demo:project:core:v1',
            'name' => '[DEMO] 核心业务平台',
            'system_type' => 1,
            'description' => '[IPMS_DEMO_MANAGED:v1] 核心业务流程演示项目',
        ],
        [
            'seed_marker' => 'ipms:demo:project:collaboration:v1',
            'name' => '[DEMO] 协同办公平台',
            'system_type' => 2,
            'description' => '[IPMS_DEMO_MANAGED:v1] 内部协同流程演示项目',
        ],
    ];

    private const PROJECT_MEMBERS = [
        'it_pm' => 'pm',
        'it_member' => 'member',
        'supplier_pm' => 'pm',
        'supplier_dev' => 'member',
        'supplier_tester' => 'member',
    ];

    public function run(): void
    {
        DemoSeedGuard::assertProductionAuthorized();
        $password = $this->requiredPassword();

        DB::transaction(function () use ($password): void {
            $roleIds = $this->roleIds();
            $this->assertNoIdentityCollisions();

            $organizationIds = $this->seedOrganizations();
            $userIds = $this->seedUsers($password, $roleIds, $organizationIds);
            $this->seedProjects($userIds, $organizationIds[2]);
        });
    }

    private function requiredPassword(): string
    {
        $password = config('ipms.demo_password');

        if (! is_string($password) || trim($password) === '') {
            throw new LogicException('IPMS_DEMO_PASSWORD is required to seed demo workflow data.');
        }

        return $password;
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

    private function assertNoIdentityCollisions(): void
    {
        $this->assertNoUserCollisions();
        $this->assertNoOrganizationCollisions();
        $this->assertNoProjectCollisions();
    }

    private function assertNoUserCollisions(): void
    {
        foreach (self::ROLE_USERS as $roleCode => $user) {
            if (DB::table('users')->where('seed_marker', $this->userMarker($roleCode))->exists()) {
                continue;
            }

            if (DB::table('users')
                ->whereRaw('LOWER(username) = ?', [strtolower($user['username'])])
                ->exists()) {
                throw new LogicException("Reserved demo user marker collision: {$user['username']}.");
            }

            $expectedEmail = $user['username'].'@ipms.local';
            if (DB::table('users')
                ->whereRaw('LOWER(email) = ?', [strtolower($expectedEmail)])
                ->exists()) {
                throw new LogicException("Reserved demo email marker collision: {$expectedEmail}.");
            }
        }
    }

    private function assertNoOrganizationCollisions(): void
    {
        foreach (self::ORGANIZATIONS as $type => $organization) {
            $matching = DB::table('organizations')
                ->where('name', $organization['name'])
                ->where('org_type', $type)
                ->count();

            if ($matching > 1) {
                throw new LogicException("Canonical organization collision: {$organization['name']}.");
            }

            if ($matching === 0 && DB::table('organizations')->where('name', $organization['name'])->exists()) {
                throw new LogicException("Canonical organization type collision: {$organization['name']}.");
            }
        }
    }

    private function assertNoProjectCollisions(): void
    {
        foreach (self::PROJECTS as $project) {
            if (DB::table('projects')->where('seed_marker', $project['seed_marker'])->exists()) {
                continue;
            }

            if (DB::table('projects')
                ->whereRaw('LOWER(name) = ?', [strtolower($project['name'])])
                ->exists()) {
                throw new LogicException("Reserved demo project marker collision: {$project['name']}.");
            }
        }
    }

    private function seedOrganizations(): array
    {
        $organizationIds = [];

        foreach (self::ORGANIZATIONS as $type => $organization) {
            $organizationId = DB::table('organizations')
                ->where('name', $organization['name'])
                ->where('org_type', $type)
                ->value('id');

            if ($organizationId === null) {
                $organizationId = DB::table('organizations')->insertGetId([
                    'parent_id' => null,
                    'name' => $organization['name'],
                    'org_type' => $type,
                    'description' => $organization['description'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $organizationIds[$type] = $organizationId;
        }

        return $organizationIds;
    }

    private function seedUsers(string $password, array $roleIds, array $organizationIds): array
    {
        $userIds = [];

        foreach (self::ROLE_USERS as $roleCode => $user) {
            $seedMarker = $this->userMarker($roleCode);
            $managed = DB::table('users')->where('seed_marker', $seedMarker)->first();

            if ($managed === null) {
                $userId = DB::table('users')->insertGetId([
                    'username' => $user['username'],
                    'password' => Hash::make($password),
                    'user_type' => $user['user_type'],
                    'display_name' => $user['display_name'],
                    'first_name' => '',
                    'last_name' => '',
                    'email' => $user['username'].'@ipms.local',
                    'is_active' => true,
                    'is_staff' => $roleCode === 'super_admin',
                    'must_change_password' => false,
                    'is_disabled' => false,
                    'seed_marker' => $seedMarker,
                    'created_by_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $userId = $managed->id;
            }

            $userIds[$roleCode] = $userId;
            $this->syncExactRole($userId, $roleIds[$roleCode]);
            $this->syncExactOrganization($userId, $organizationIds[$user['user_type']], $roleCode);

            if (! DB::table('notification_configs')->where('user_id', $userId)->exists()) {
                DB::table('notification_configs')->insert([
                    'user_id' => $userId,
                    'remind_enabled' => true,
                    'remind_days_before' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $userIds;
    }

    private function syncExactRole(int $userId, int $roleId): void
    {
        DB::table('role_user')
            ->where('user_id', $userId)
            ->where('role_id', '<>', $roleId)
            ->delete();

        if (! DB::table('role_user')->where([
            'user_id' => $userId,
            'role_id' => $roleId,
        ])->exists()) {
            DB::table('role_user')->insert([
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_by_id' => null,
                'assigned_at' => now(),
            ]);
        }
    }

    private function syncExactOrganization(int $userId, int $organizationId, string $roleCode): void
    {
        DB::table('organization_user')
            ->where('user_id', $userId)
            ->where('organization_id', '<>', $organizationId)
            ->delete();

        $existing = DB::table('organization_user')->where([
            'user_id' => $userId,
            'organization_id' => $organizationId,
        ])->first();

        if ($existing === null) {
            DB::table('organization_user')->insert([
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'role_in_org' => $roleCode,
                'is_primary' => true,
                'assigned_at' => now(),
            ]);

            return;
        }

        if ($existing->role_in_org !== $roleCode || ! (bool) $existing->is_primary) {
            DB::table('organization_user')
                ->where('id', $existing->id)
                ->update([
                    'role_in_org' => $roleCode,
                    'is_primary' => true,
                    'assigned_at' => now(),
                ]);
        }
    }

    private function seedProjects(array $userIds, int $supplierOrganizationId): void
    {
        foreach (self::PROJECTS as $project) {
            $managed = DB::table('projects')
                ->where('seed_marker', $project['seed_marker'])
                ->first();

            if ($managed === null) {
                $projectId = DB::table('projects')->insertGetId([
                    'name' => $project['name'],
                    'system_type' => $project['system_type'],
                    'description' => $project['description'],
                    'status' => 1,
                    'manager_id' => $userIds['it_pm'],
                    'supplier_org_id' => $supplierOrganizationId,
                    'seed_marker' => $project['seed_marker'],
                    'created_by_id' => $userIds['super_admin'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $projectId = $managed->id;
            }

            $this->syncExactProjectMembers($projectId, $userIds);
        }
    }

    private function syncExactProjectMembers(int $projectId, array $userIds): void
    {
        $expectedUserIds = array_map(
            static fn (string $roleCode): int => $userIds[$roleCode],
            array_keys(self::PROJECT_MEMBERS),
        );

        DB::table('project_members')
            ->where('project_id', $projectId)
            ->whereNotIn('user_id', $expectedUserIds)
            ->delete();

        foreach (self::PROJECT_MEMBERS as $roleCode => $projectRole) {
            $existing = DB::table('project_members')->where([
                'project_id' => $projectId,
                'user_id' => $userIds[$roleCode],
            ])->first();

            if ($existing === null) {
                DB::table('project_members')->insert([
                    'project_id' => $projectId,
                    'user_id' => $userIds[$roleCode],
                    'role_in_project' => $projectRole,
                    'assigned_by_id' => $userIds['super_admin'],
                    'assigned_at' => now(),
                    'created_at' => now(),
                ]);

                continue;
            }

            if ($existing->role_in_project !== $projectRole) {
                DB::table('project_members')
                    ->where('id', $existing->id)
                    ->update([
                        'role_in_project' => $projectRole,
                        'assigned_by_id' => $userIds['super_admin'],
                        'assigned_at' => now(),
                    ]);
            }
        }
    }

    private function userMarker(string $roleCode): string
    {
        return "ipms:demo:user:{$roleCode}:v1";
    }
}
