<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoWorkflowSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

final class DemoWorkflowSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ROLE_CODES = [
        'super_admin',
        'it_pm',
        'it_member',
        'supplier_pm',
        'supplier_dev',
        'supplier_tester',
        'requester',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');
    }

    public function test_demo_seeder_requires_an_environment_password(): void
    {
        config()->set('ipms.demo_password');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('IPMS_DEMO_PASSWORD');

        $this->seed(DemoWorkflowSeeder::class);
    }

    public function test_demo_seeder_creates_a_complete_multi_role_workflow(): void
    {
        $this->seedPrerequisites();
        $password = bin2hex(random_bytes(16));
        config()->set('ipms.demo_password', $password);

        $this->seed(DemoWorkflowSeeder::class);

        $this->assertSame(
            self::ROLE_CODES,
            DB::table('users')
                ->join('role_user', 'users.id', '=', 'role_user.user_id')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->whereIn('users.username', $this->demoUsernames())
                ->orderBy('users.id')
                ->pluck('roles.code')
                ->all(),
        );

        $this->assertDatabaseCount('organizations', 3);
        foreach ([1, 2, 3] as $organizationType) {
            $this->assertDatabaseHas('organizations', [
                'org_type' => $organizationType,
                'is_active' => true,
            ]);
        }

        $this->assertDatabaseCount('projects', 2);
        $this->assertDatabaseHas('projects', ['name' => '[DEMO] 核心业务平台']);
        $this->assertDatabaseHas('projects', ['name' => '[DEMO] 协同办公平台']);

        $internalMemberIds = DB::table('users')
            ->whereIn('username', ['demo.it_pm', 'demo.it_member'])
            ->pluck('id');
        $supplierMemberIds = DB::table('users')
            ->whereIn('username', ['demo.supplier_pm', 'demo.supplier_dev', 'demo.supplier_tester'])
            ->pluck('id');

        foreach (DB::table('projects')->pluck('id') as $projectId) {
            $this->assertSame(
                $internalMemberIds->sort()->values()->all(),
                DB::table('project_members')
                    ->where('project_id', $projectId)
                    ->whereIn('user_id', $internalMemberIds)
                    ->pluck('user_id')
                    ->sort()
                    ->values()
                    ->all(),
            );
            $this->assertSame(
                $supplierMemberIds->sort()->values()->all(),
                DB::table('project_members')
                    ->where('project_id', $projectId)
                    ->whereIn('user_id', $supplierMemberIds)
                    ->pluck('user_id')
                    ->sort()
                    ->values()
                    ->all(),
            );
        }

        $this->assertSame(7, DB::table('organization_user')->count());

        foreach ($this->demoUsernames() as $username) {
            $storedPassword = DB::table('users')->where('username', $username)->value('password');
            $this->assertNotSame($password, $storedPassword);
            $this->assertTrue(Hash::check($password, $storedPassword));
        }
    }

    public function test_demo_seeder_is_idempotent(): void
    {
        $this->seedPrerequisites();
        config()->set('ipms.demo_password', bin2hex(random_bytes(16)));

        $this->seed(DemoWorkflowSeeder::class);
        $firstCounts = $this->workflowCounts();

        $this->seed(DemoWorkflowSeeder::class);

        $this->assertSame($firstCounts, $this->workflowCounts());
    }

    public function test_admin_seeder_requires_the_environment_password(): void
    {
        config()->set('ipms.demo_password');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('IPMS_DEMO_PASSWORD');

        $this->seed(AdminUserSeeder::class);
    }

    public function test_database_seeder_keeps_demo_data_disabled_by_default(): void
    {
        $password = bin2hex(random_bytes(16));
        config()->set('ipms.demo_password', $password);
        config()->set('ipms.seed_demo', false);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['username' => 'admin']);
        $this->assertSame(
            0,
            DB::table('users')->whereIn('username', $this->demoUsernames())->count(),
        );

        $storedPassword = DB::table('users')->where('username', 'admin')->value('password');
        $this->assertNotSame($password, $storedPassword);
        $this->assertTrue(Hash::check($password, $storedPassword));
    }

    public function test_database_seeder_creates_demo_data_only_when_enabled(): void
    {
        config()->set('ipms.demo_password', bin2hex(random_bytes(16)));
        config()->set('ipms.seed_demo', true);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            count(self::ROLE_CODES),
            DB::table('users')->whereIn('username', $this->demoUsernames())->count(),
        );
        $this->assertDatabaseCount('projects', 2);
    }

    private function seedPrerequisites(): void
    {
        $this->seed([
            PermissionSeeder::class,
            RoleSeeder::class,
            RolePermissionSeeder::class,
            OrganizationSeeder::class,
        ]);
    }

    private function demoUsernames(): array
    {
        return array_map(static fn (string $code): string => "demo.{$code}", self::ROLE_CODES);
    }

    private function workflowCounts(): array
    {
        return [
            'users' => DB::table('users')->whereIn('username', $this->demoUsernames())->count(),
            'role_user' => DB::table('role_user')->count(),
            'organization_user' => DB::table('organization_user')->count(),
            'projects' => DB::table('projects')->count(),
            'project_members' => DB::table('project_members')->count(),
            'notification_configs' => DB::table('notification_configs')->count(),
        ];
    }
}
