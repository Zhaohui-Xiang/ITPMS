<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoWorkflowSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class SeedMarkerGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_database_seeder_rejects_unauthorized_production_before_rows_or_sequences_change(): void
    {
        $this->app['env'] = 'production';
        config()->set('ipms.demo_password', $this->runtimePassword());
        config()->set('ipms.seed_demo', true);
        config()->set('ipms.allow_production_demo_seed', false);

        $rowsBefore = $this->tableCounts();
        $sequencesBefore = $this->sequenceStates();

        $this->assertLogicException(fn () => $this->runSeeder(DatabaseSeeder::class), 'production');

        $this->assertSame($rowsBefore, $this->tableCounts());
        $this->assertSame($sequencesBefore, $this->sequenceStates());
    }

    public function test_direct_demo_seeder_uses_the_same_production_guard_before_database_changes(): void
    {
        $this->app['env'] = 'production';
        config()->set('ipms.demo_password', $this->runtimePassword());
        config()->set('ipms.seed_demo', false);
        config()->set('ipms.allow_production_demo_seed', true);

        $rowsBefore = $this->tableCounts();
        $sequencesBefore = $this->sequenceStates();

        $this->assertLogicException(fn () => $this->runSeeder(DemoWorkflowSeeder::class), 'production');

        $this->assertSame($rowsBefore, $this->tableCounts());
        $this->assertSame($sequencesBefore, $this->sequenceStates());
    }

    public function test_new_admin_demo_users_and_projects_receive_persistent_markers(): void
    {
        config()->set('ipms.demo_password', $this->runtimePassword());
        config()->set('ipms.seed_demo', true);
        config()->set('ipms.allow_production_demo_seed', false);

        $this->runSeeder(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', [
            'username' => 'admin',
            'seed_marker' => 'ipms:admin:user:v1',
        ]);
        $this->assertSame(
            7,
            DB::table('users')->where('seed_marker', 'like', 'ipms:demo:user:%:v1')->count(),
        );
        $this->assertSame(
            2,
            DB::table('projects')->where('seed_marker', 'like', 'ipms:demo:project:%:v1')->count(),
        );
    }

    public function test_fully_spoofed_reserved_demo_user_without_marker_is_not_adopted(): void
    {
        $this->seedPrerequisites();
        $collision = User::factory()->internal()->create([
            'username' => 'demo.it_pm',
            'email' => 'demo.it_pm@ipms.local',
            'display_name' => 'Spoofed Demo Identity',
        ]);
        $this->configureDemo();

        $this->assertLogicException(fn () => $this->runSeeder(DemoWorkflowSeeder::class), 'marker');

        $this->assertDatabaseHas('users', [
            'id' => $collision->id,
            'display_name' => 'Spoofed Demo Identity',
            'seed_marker' => null,
        ]);
        $this->assertDatabaseCount('role_user', 0);
        $this->assertDatabaseCount('organization_user', 0);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_case_variant_reserved_username_without_marker_is_rejected_and_unique_index_is_case_insensitive(): void
    {
        $this->seedPrerequisites();
        User::factory()->internal()->create([
            'username' => 'Demo.IT_PM',
            'email' => 'demo.it_pm@ipms.local',
        ]);
        $this->configureDemo();

        $this->assertLogicException(fn () => $this->runSeeder(DemoWorkflowSeeder::class), 'marker');

        $this->expectException(QueryException::class);
        User::factory()->internal()->create([
            'username' => 'demo.it_pm',
            'email' => 'another@example.test',
        ]);
    }

    public function test_case_variant_reserved_project_without_marker_is_not_adopted(): void
    {
        $this->seedPrerequisites();
        $owner = User::factory()->internal()->create();
        $projectId = DB::table('projects')->insertGetId([
            'name' => '[demo] 核心业务平台',
            'system_type' => 1,
            'description' => '[IPMS_DEMO_MANAGED:v1] Fully spoofed project',
            'status' => 1,
            'manager_id' => $owner->id,
            'created_by_id' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->configureDemo();

        $this->assertLogicException(fn () => $this->runSeeder(DemoWorkflowSeeder::class), 'marker');

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'name' => '[demo] 核心业务平台',
            'seed_marker' => null,
        ]);
        $this->assertSame(0, DB::table('users')->where('seed_marker', 'like', 'ipms:demo:user:%:v1')->count());
    }

    public function test_existing_admin_without_marker_is_not_granted_super_admin(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->internal()->create([
            'username' => 'ADMIN',
            'email' => 'admin@ipms.local',
        ]);
        config()->set('ipms.demo_password', $this->runtimePassword());

        $this->assertLogicException(fn () => $this->runSeeder(AdminUserSeeder::class), 'marker');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'seed_marker' => null,
        ]);
        $this->assertDatabaseCount('role_user', 0);
    }

    public function test_correct_relationship_timestamps_are_stable_on_rerun(): void
    {
        $this->seedPrerequisites();
        $this->configureDemo();
        CarbonImmutable::setTestNow('2026-08-17 10:00:00');
        $this->runSeeder(DemoWorkflowSeeder::class);

        $before = $this->relationshipTimestamps();

        CarbonImmutable::setTestNow('2026-08-18 10:00:00');
        $this->runSeeder(DemoWorkflowSeeder::class);

        $this->assertSame($before, $this->relationshipTimestamps());
    }

    private function tableCounts(): array
    {
        $counts = [];

        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $table) {
            $counts[$table->tablename] = DB::table($table->tablename)->count();
        }

        return $counts;
    }

    private function sequenceStates(): array
    {
        $states = [];

        foreach (DB::select("SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' ORDER BY sequencename") as $sequence) {
            $identifier = '"'.str_replace('"', '""', $sequence->sequencename).'"';
            $state = DB::selectOne("SELECT last_value, is_called FROM {$identifier}");
            $states[$sequence->sequencename] = [(int) $state->last_value, (bool) $state->is_called];
        }

        return $states;
    }

    private function relationshipTimestamps(): array
    {
        $demoUserIds = DB::table('users')
            ->where('seed_marker', 'like', 'ipms:demo:user:%:v1')
            ->pluck('id');

        return [
            'roles' => DB::table('role_user')
                ->whereIn('user_id', $demoUserIds)
                ->orderBy('user_id')
                ->orderBy('role_id')
                ->get(['user_id', 'role_id', 'assigned_at'])
                ->map(fn ($row) => (array) $row)
                ->all(),
            'organizations' => DB::table('organization_user')
                ->whereIn('user_id', $demoUserIds)
                ->orderBy('user_id')
                ->orderBy('organization_id')
                ->get(['user_id', 'organization_id', 'assigned_at'])
                ->map(fn ($row) => (array) $row)
                ->all(),
            'projects' => DB::table('project_members')
                ->whereIn('project_id', DB::table('projects')->where('seed_marker', 'like', 'ipms:demo:project:%:v1')->pluck('id'))
                ->orderBy('project_id')
                ->orderBy('user_id')
                ->get(['project_id', 'user_id', 'assigned_at'])
                ->map(fn ($row) => (array) $row)
                ->all(),
        ];
    }

    private function configureDemo(): void
    {
        config()->set('ipms.demo_password', $this->runtimePassword());
        config()->set('ipms.seed_demo', true);
        config()->set('ipms.allow_production_demo_seed', false);
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

    private function runSeeder(string $seeder): void
    {
        $this->app->make($seeder)->run();
    }

    private function runtimePassword(): string
    {
        return bin2hex(random_bytes(16));
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
