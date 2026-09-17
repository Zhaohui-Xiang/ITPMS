<?php

namespace Tests\Feature\Seeders;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_organization_create_after_seed_does_not_collide(): void
    {
        DB::statement('ALTER SEQUENCE organizations_id_seq RESTART WITH 1');
        $this->seed(OrganizationSeeder::class);
        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/organizations', ['name' => 'New IT team', 'org_type' => 1, 'parent_id' => null])
            ->assertCreated()->assertJsonPath('data.name', 'New IT team');
        $this->assertDatabaseCount('organizations', 4);
    }

    public function test_upgrade_repairs_existing_explicit_ids_and_never_rewinds_sequence(): void
    {
        DB::statement('ALTER SEQUENCE organizations_id_seq RESTART WITH 1');
        DB::table('organizations')->insert(['id' => 80, 'name' => 'Existing node', 'org_type' => 2]);
        $path = database_path('migrations/2026_09_15_000001_repair_organization_id_sequence.php');
        $this->assertFileExists($path);
        $migration = require $path;
        $migration->up();
        $this->assertGreaterThan(80, Organization::create(['name' => 'After upgrade', 'org_type' => 1])->id);
        DB::statement("SELECT setval('organizations_id_seq', 200, true)");
        $migration->up();
        $this->assertSame(201, Organization::create(['name' => 'No rewind', 'org_type' => 1])->id);
    }
}
