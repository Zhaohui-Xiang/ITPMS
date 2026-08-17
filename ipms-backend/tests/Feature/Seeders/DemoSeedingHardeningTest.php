<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
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

final class DemoSeedingHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO_PROJECTS = [
        '[DEMO] 核心业务平台',
        '[DEMO] 协同办公平台',
    ];

    private const DEMO_ROLES = [
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

    public function test_production_requires_both_demo_authorization_switches_before_writing(): void
    {
        $this->app['env'] = 'production';
        config()->set('ipms.demo_password', $this->runtimePassword());

        foreach ([[false, true], [true, false], [false, false]] as [$seedDemo, $allowProduction]) {
            config()->set('ipms.seed_demo', $seedDemo);
            config()->set('ipms.allow_production_demo_seed', $allowProduction);

            $this->assertLogicException(
                fn () => $this->runSeeder(DemoWorkflowSeeder::class),
                'production',
            );
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_production_allows_demo_seed_only_with_both_switches(): void
    {
        $this->seedPrerequisites();
        $this->app['env'] = 'production';
        $this->configureDemo(true, true);

        $this->runSeeder(DemoWorkflowSeeder::class);

        $this->assertSame(7, DB::table('users')->whereIn('username', $this->demoUsernames())->count());
        $this->assertDatabaseCount('projects', 2);
    }

    public function test_database_seeder_allows_production_with_both_switches(): void
    {
        $this->app['env'] = 'production';
        $this->configureDemo(true, true);

        $this->runSeeder(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['username' => 'admin']);
        $this->assertSame(7, DB::table('users')->whereIn('username', $this->demoUsernames())->count());
        $this->assertDatabaseCount('projects', 2);
    }

    public function test_database_seeder_password_preflight_leaves_no_rows_and_can_retry(): void
    {
        config()->set('ipms.demo_password');
        config()->set('ipms.seed_demo', false);

        $this->assertLogicException(
            fn () => $this->runSeeder(DatabaseSeeder::class),
            'IPMS_DEMO_PASSWORD',
        );

        foreach (['permissions', 'roles', 'organizations', 'users'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        config()->set('ipms.demo_password', $this->runtimePassword());
        $this->runSeeder(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['username' => 'admin']);
        $this->assertSame(7, DB::table('roles')->count());
    }

    public function test_database_seeder_rolls_back_when_production_demo_authorization_fails(): void
    {
        $this->app['env'] = 'production';
        $this->configureDemo(true, false);

        $this->assertLogicException(
            fn () => $this->runSeeder(DatabaseSeeder::class),
            'production',
        );

        foreach (['permissions', 'roles', 'organizations', 'users', 'projects'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_rerun_preserves_demo_password_hashes_and_security_fields(): void
    {
        $this->seedPrerequisites();
        $this->configureDemo();
        $this->runSeeder(DemoWorkflowSeeder::class);

        $targetId = DB::table('users')->where('username', 'demo.it_pm')->value('id');
        DB::table('users')->where('id', $targetId)->update([
            'password' => Hash::make($this->runtimePassword()),
            'is_active' => false,
            'is_disabled' => true,
            'must_change_password' => true,
            'display_name' => 'Customized Demo Manager',
        ]);

        $before = DB::table('users')
            ->whereIn('username', $this->demoUsernames())
            ->orderBy('username')
            ->get(['username', 'password', 'is_active', 'is_disabled', 'must_change_password', 'display_name'])
            ->map(fn ($row) => (array) $row)
            ->all();

        config()->set('ipms.demo_password', $this->runtimePassword());
        $this->runSeeder(DemoWorkflowSeeder::class);

        $after = DB::table('users')
            ->whereIn('username', $this->demoUsernames())
            ->orderBy('username')
            ->get(['username', 'password', 'is_active', 'is_disabled', 'must_change_password', 'display_name'])
            ->map(fn ($row) => (array) $row)
            ->all();

        $this->assertSame($before, $after);
    }

    public function test_rerun_preserves_existing_admin_password_and_security_fields(): void
    {
        $this->seed(RoleSeeder::class);
        config()->set('ipms.demo_password', $this->runtimePassword());
        $this->seed(AdminUserSeeder::class);

        DB::table('users')->where('username', 'admin')->update([
            'password' => Hash::make($this->runtimePassword()),
            'is_active' => false,
            'is_disabled' => true,
            'must_change_password' => false,
        ]);
        $before = (array) DB::table('users')->where('username', 'admin')->first();

        config()->set('ipms.demo_password', $this->runtimePassword());
        $this->seed(AdminUserSeeder::class);

        $after = (array) DB::table('users')->where('username', 'admin')->first();
        $this->assertSame($before['password'], $after['password']);
        $this->assertSame($before['is_active'], $after['is_active']);
        $this->assertSame($before['is_disabled'], $after['is_disabled']);
        $this->assertSame($before['must_change_password'], $after['must_change_password']);
    }

    public function test_rerun_restores_exact_demo_roles_organizations_and_project_members(): void
    {
        $this->seedPrerequisites();
        $this->configureDemo();
        $this->runSeeder(DemoWorkflowSeeder::class);

        $itPmId = DB::table('users')->where('username', 'demo.it_pm')->value('id');
        $requesterId = DB::table('users')->where('username', 'demo.requester')->value('id');
        $supplierDevRoleId = DB::table('roles')->where('code', 'supplier_dev')->value('id');
        $supplierOrgId = DB::table('organizations')->where('org_type', 2)->value('id');

        DB::table('role_user')->insert([
            'user_id' => $itPmId,
            'role_id' => $supplierDevRoleId,
            'assigned_at' => now(),
        ]);
        DB::table('organization_user')->insert([
            'user_id' => $itPmId,
            'organization_id' => $supplierOrgId,
            'role_in_org' => 'stale',
            'is_primary' => true,
            'assigned_at' => now(),
        ]);

        foreach (DB::table('projects')->whereIn('name', self::DEMO_PROJECTS)->pluck('id') as $projectId) {
            DB::table('project_members')->insert([
                'project_id' => $projectId,
                'user_id' => $requesterId,
                'role_in_project' => 'member',
                'assigned_at' => now(),
                'created_at' => now(),
            ]);
        }

        $outsideUser = User::factory()->internal()->create();
        $outsideProjectId = DB::table('projects')->insertGetId([
            'name' => 'Business Project',
            'system_type' => 1,
            'description' => 'Not managed by the demo seeder',
            'status' => 1,
            'manager_id' => $outsideUser->id,
            'created_by_id' => $outsideUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('project_members')->insert([
            'project_id' => $outsideProjectId,
            'user_id' => $outsideUser->id,
            'role_in_project' => 'pm',
            'assigned_at' => now(),
            'created_at' => now(),
        ]);

        $this->runSeeder(DemoWorkflowSeeder::class);

        $this->assertSame(
            ['it_pm'],
            DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $itPmId)
                ->pluck('roles.code')
                ->all(),
        );
        $this->assertSame(1, DB::table('organization_user')->where('user_id', $itPmId)->count());
        $this->assertDatabaseHas('organization_user', [
            'user_id' => $itPmId,
            'organization_id' => DB::table('organizations')->where('org_type', 1)->value('id'),
            'is_primary' => true,
        ]);

        $expectedMemberIds = DB::table('users')
            ->whereIn('username', [
                'demo.it_pm',
                'demo.it_member',
                'demo.supplier_pm',
                'demo.supplier_dev',
                'demo.supplier_tester',
            ])
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        foreach (DB::table('projects')->whereIn('name', self::DEMO_PROJECTS)->pluck('id') as $projectId) {
            $this->assertSame(
                $expectedMemberIds,
                DB::table('project_members')
                    ->where('project_id', $projectId)
                    ->pluck('user_id')
                    ->sort()
                    ->values()
                    ->all(),
            );
        }

        $this->assertDatabaseHas('project_members', [
            'project_id' => $outsideProjectId,
            'user_id' => $outsideUser->id,
        ]);
    }

    public function test_reserved_demo_username_collision_aborts_without_overwriting(): void
    {
        $this->seedPrerequisites();
        $collision = User::factory()->internal()->create([
            'username' => 'demo.it_pm',
            'email' => 'business.user@example.test',
            'display_name' => 'Business User',
        ]);
        $before = (array) DB::table('users')->where('id', $collision->id)->first();
        $this->configureDemo();

        $this->assertLogicException(
            fn () => $this->runSeeder(DemoWorkflowSeeder::class),
            'collision',
        );

        $after = (array) DB::table('users')->where('id', $collision->id)->first();
        $this->assertSame($before, $after);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_unmarked_reserved_project_name_collision_aborts_without_overwriting(): void
    {
        $this->seedPrerequisites();
        $owner = User::factory()->internal()->create();
        $projectId = DB::table('projects')->insertGetId([
            'name' => self::DEMO_PROJECTS[0],
            'system_type' => 2,
            'description' => 'Customer-owned business project',
            'status' => 2,
            'manager_id' => $owner->id,
            'created_by_id' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = (array) DB::table('projects')->where('id', $projectId)->first();
        $this->configureDemo();

        $this->assertLogicException(
            fn () => $this->runSeeder(DemoWorkflowSeeder::class),
            'collision',
        );

        $after = (array) DB::table('projects')->where('id', $projectId)->first();
        $this->assertSame($before, $after);
        $this->assertSame(0, DB::table('users')->whereIn('username', $this->demoUsernames())->count());
    }

    public function test_existing_canonical_organizations_and_managed_project_fields_are_not_overwritten(): void
    {
        $this->seedPrerequisites();
        DB::table('organizations')->where('org_type', 1)->update([
            'description' => 'Customized internal organization',
            'is_active' => false,
        ]);
        $this->configureDemo();
        $this->runSeeder(DemoWorkflowSeeder::class);

        $customManager = User::factory()->internal()->create();
        DB::table('projects')->where('name', self::DEMO_PROJECTS[0])->update([
            'system_type' => 2,
            'description' => '[IPMS_DEMO_MANAGED:v1] Customized project',
            'status' => 3,
            'manager_id' => $customManager->id,
            'supplier_org_id' => null,
            'created_by_id' => $customManager->id,
        ]);
        $projectBefore = (array) DB::table('projects')->where('name', self::DEMO_PROJECTS[0])->first();

        $this->runSeeder(DemoWorkflowSeeder::class);

        $this->assertDatabaseHas('organizations', [
            'org_type' => 1,
            'description' => 'Customized internal organization',
            'is_active' => false,
        ]);
        $projectAfter = (array) DB::table('projects')->where('name', self::DEMO_PROJECTS[0])->first();
        foreach (['system_type', 'description', 'status', 'manager_id', 'supplier_org_id', 'created_by_id'] as $field) {
            $this->assertSame($projectBefore[$field], $projectAfter[$field]);
        }
    }

    private function configureDemo(bool $seedDemo = true, bool $allowProduction = false): void
    {
        config()->set('ipms.demo_password', $this->runtimePassword());
        config()->set('ipms.seed_demo', $seedDemo);
        config()->set('ipms.allow_production_demo_seed', $allowProduction);
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
        return array_map(static fn (string $role): string => "demo.{$role}", self::DEMO_ROLES);
    }

    private function runtimePassword(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function runSeeder(string $seeder): void
    {
        $this->app->make($seeder)->run();
    }

    private function assertLogicException(callable $operation, string $messageFragment): void
    {
        try {
            $operation();
            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException $exception) {
            $this->assertStringContainsStringIgnoringCase($messageFragment, $exception->getMessage());
        }
    }
}
