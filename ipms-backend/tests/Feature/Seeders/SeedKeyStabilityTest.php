<?php

namespace Tests\Feature\Seeders;

use App\Models\Project;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DemoWorkflowSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeedKeyStabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');
    }

    public function test_renamed_managed_projects_are_found_by_independent_seed_keys(): void
    {
        $this->seedPrerequisites();
        $this->configureDemo();
        $this->runSeeder(DemoWorkflowSeeder::class);

        $renames = [
            'ipms:demo:project:core:v1' => 'Renamed Core Platform',
            'ipms:demo:project:collaboration:v1' => 'Renamed Collaboration Platform',
        ];

        foreach ($renames as $marker => $name) {
            DB::table('projects')->where('seed_marker', $marker)->update(['name' => $name]);
        }

        $this->runSeeder(DemoWorkflowSeeder::class);

        $this->assertSame(2, DB::table('projects')->whereIn('seed_marker', array_keys($renames))->count());
        $this->assertDatabaseCount('projects', 2);

        foreach ($renames as $marker => $name) {
            $this->assertDatabaseHas('projects', [
                'seed_marker' => $marker,
                'name' => $name,
            ]);
            $this->assertSame(1, DB::table('projects')->where('seed_marker', $marker)->count());
        }

        $this->assertDatabaseMissing('projects', ['name' => '[DEMO] 核心业务平台']);
        $this->assertDatabaseMissing('projects', ['name' => '[DEMO] 协同办公平台']);
    }

    public function test_renamed_demo_user_is_found_by_role_seed_key_and_relations_converge(): void
    {
        $this->seedPrerequisites();
        $this->configureDemo();
        $this->runSeeder(DemoWorkflowSeeder::class);

        $marker = 'ipms:demo:user:it_pm:v1';
        $user = DB::table('users')->where('seed_marker', $marker)->first();
        DB::table('users')->where('id', $user->id)->update([
            'username' => 'custom.internal.pm',
            'email' => 'custom.internal.pm@example.test',
            'display_name' => 'Customized Internal PM',
        ]);

        DB::table('role_user')->insert([
            'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('code', 'supplier_dev')->value('id'),
            'assigned_at' => now(),
        ]);
        DB::table('organization_user')->insert([
            'user_id' => $user->id,
            'organization_id' => DB::table('organizations')->where('org_type', 2)->value('id'),
            'role_in_org' => 'stale',
            'is_primary' => true,
            'assigned_at' => now(),
        ]);

        $this->runSeeder(DemoWorkflowSeeder::class);

        $this->assertSame(
            7,
            DB::table('users')->where('seed_marker', 'like', 'ipms:demo:user:%:v1')->count(),
        );
        $this->assertSame(1, DB::table('users')->where('seed_marker', $marker)->count());
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'seed_marker' => $marker,
            'username' => 'custom.internal.pm',
            'email' => 'custom.internal.pm@example.test',
            'display_name' => 'Customized Internal PM',
        ]);
        $this->assertDatabaseMissing('users', ['username' => 'demo.it_pm']);

        $this->assertSame(
            ['it_pm'],
            DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $user->id)
                ->pluck('roles.code')
                ->all(),
        );
        $this->assertSame(1, DB::table('organization_user')->where('user_id', $user->id)->count());
        $this->assertSame(
            1,
            DB::table('organization_user')
                ->join('organizations', 'organizations.id', '=', 'organization_user.organization_id')
                ->where('organization_user.user_id', $user->id)
                ->where('organizations.org_type', 1)
                ->where('organization_user.is_primary', true)
                ->count(),
        );
    }

    public function test_renamed_admin_is_found_by_its_seed_key_without_field_reset(): void
    {
        $this->seed(RoleSeeder::class);
        config()->set('ipms.demo_password', $this->runtimePassword());
        $this->runSeeder(AdminUserSeeder::class);

        $marker = 'ipms:admin:user:v1';
        $admin = DB::table('users')->where('seed_marker', $marker)->first();
        DB::table('users')->where('id', $admin->id)->update([
            'username' => 'custom.admin',
            'email' => 'custom.admin@example.test',
            'display_name' => 'Customized Administrator',
        ]);

        $this->runSeeder(AdminUserSeeder::class);

        $this->assertSame(1, DB::table('users')->where('seed_marker', $marker)->count());
        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'seed_marker' => $marker,
            'username' => 'custom.admin',
            'email' => 'custom.admin@example.test',
            'display_name' => 'Customized Administrator',
        ]);
        $this->assertDatabaseMissing('users', ['username' => 'admin']);
        $this->assertSame(
            ['super_admin'],
            DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $admin->id)
                ->pluck('roles.code')
                ->all(),
        );
    }

    public function test_user_seed_marker_is_unique_when_not_null(): void
    {
        $first = User::factory()->internal()->create();
        $second = User::factory()->internal()->create();
        DB::table('users')->where('id', $first->id)->update([
            'seed_marker' => 'ipms:test:user:unique:v1',
        ]);

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $second->id)->update([
            'seed_marker' => 'ipms:test:user:unique:v1',
        ]);
    }

    public function test_project_seed_marker_is_unique_when_not_null(): void
    {
        $first = Project::factory()->create();
        $second = Project::factory()->create();
        DB::table('projects')->where('id', $first->id)->update([
            'seed_marker' => 'ipms:test:project:unique:v1',
        ]);

        $this->expectException(QueryException::class);

        DB::table('projects')->where('id', $second->id)->update([
            'seed_marker' => 'ipms:test:project:unique:v1',
        ]);
    }

    public function test_seed_markers_are_hidden_from_model_arrays_and_json(): void
    {
        $this->seedPrerequisites();
        $this->configureDemo();
        $this->runSeeder(DemoWorkflowSeeder::class);

        $user = User::query()->whereNotNull('seed_marker')->firstOrFail();
        $project = Project::query()->whereNotNull('seed_marker')->firstOrFail();

        $this->assertArrayNotHasKey('seed_marker', $user->toArray());
        $this->assertArrayNotHasKey('seed_marker', $project->toArray());
        $this->assertStringNotContainsString('ipms:demo:user:', $user->toJson());
        $this->assertStringNotContainsString('ipms:demo:project:', $project->toJson());
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
}
