<?php
namespace Tests\Feature\Api;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RequirementProjectOptionsTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_first_time_requester_can_select_projects_without_reading_project_details(): void
    {
        $requester = User::factory()->withRole('requester')->create();
        $project = Project::factory()->create(['status' => 1]);
        Project::factory()->create(['status' => 3]);
        $this->actingAs($requester)->getJson('/api/projects')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/requirements/project-options')->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0', ['id' => $project->id, 'name' => $project->name]);
        $this->getJson("/api/projects/{$project->id}")->assertForbidden();
        $this->postJson('/api/requirements', [
            'title' => 'Initial requirement', 'description' => 'First submission', 'priority' => 2,
            'requirement_type' => 1, 'project_ids' => [$project->id],
        ])->assertCreated();
    }

    public function test_internal_options_stay_scoped_and_non_submitters_are_denied(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $own = Project::factory()->create(['manager_id' => $manager->id, 'status' => 1]);
        Project::factory()->create(['status' => 1]);
        $this->actingAs($manager)->getJson('/api/requirements/project-options')->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $own->id);
        $this->actingAs(User::factory()->withRole('supplier_dev')->create())
            ->getJson('/api/requirements/project-options')->assertForbidden();
    }

    public function test_options_require_authentication(): void
    {
        $this->getJson('/api/requirements/project-options')->assertUnauthorized();
    }
}
