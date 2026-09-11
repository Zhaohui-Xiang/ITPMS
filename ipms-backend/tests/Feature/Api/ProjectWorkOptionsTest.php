<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProjectWorkOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_internal_manager_gets_only_active_project_affiliated_task_names(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['manager'])->getJson('/api/users')->assertForbidden();

        $response = $this->actingAs($f['manager'])
            ->getJson($this->url($f['project'], 'task'))->assertOk();
        $this->assertEqualsCanonicalizing(
            array_map(fn ($user) => $user->id, [
                $f['manager'], $f['member'], $f['supplierPm'], $f['developer'],
                $f['siblingDeveloper'], $f['explicitDeveloper'], $f['tester'],
            ]),
            array_column($response->json('data'), 'id'),
        );
        foreach ($response->json('data') as $option) {
            $this->assertSame(['id', 'display_name'], array_keys($option));
            $this->assertSame(User::findOrFail($option['id'])->display_name, $option['display_name']);
        }
    }

    public function test_defect_options_only_include_affiliated_supplier_developers(): void
    {
        $f = $this->fixture();
        foreach ([$f['manager'], $f['supplierPm']] as $viewer) {
            $response = $this->actingAs($viewer)
                ->getJson($this->url($f['project'], 'defect'))->assertOk();
            $this->assertEqualsCanonicalizing(
                [$f['developer']->id, $f['siblingDeveloper']->id, $f['explicitDeveloper']->id],
                array_column($response->json('data'), 'id'),
            );
            foreach ($response->json('data') as $option) {
                $this->assertSame(['id', 'display_name'], array_keys($option));
            }
        }
    }

    public function test_project_visibility_alone_does_not_expose_assignment_options(): void
    {
        $f = $this->fixture();
        $requester = User::factory()->withRole('requester')->create();
        $requirement = Requirement::factory()->create(['submitter_id' => $requester->id]);
        RequirementProject::factory()->for($requirement)->for($f['project'])->create();

        foreach ([$requester, $f['developer'], $f['tester']] as $viewer) {
            $this->actingAs($viewer)->getJson("/api/projects/{$f['project']->id}")->assertOk();
            foreach (['task', 'defect'] as $type) {
                $this->actingAs($viewer)->getJson($this->url($f['project'], $type))
                    ->assertForbidden()->assertJsonMissingPath('data.0');
            }
        }

        $this->actingAs($f['member'])->getJson($this->url($f['project'], 'task'))->assertOk();
        $this->actingAs($f['member'])->getJson($this->url($f['project'], 'defect'))->assertForbidden();
    }

    public function test_unrelated_managers_and_other_suppliers_cannot_enumerate_options(): void
    {
        $f = $this->fixture();
        $otherManager = User::factory()->withRole('it_pm')->create();
        $otherSupplierPm = User::factory()->withRole('supplier_pm')->create();
        $otherSupplierPm->organizations()->attach($f['otherSupplier']);
        foreach ([$otherManager, $otherSupplierPm] as $viewer) {
            foreach (['task', 'defect'] as $type) {
                $this->actingAs($viewer)->getJson($this->url($f['project'], $type))
                    ->assertForbidden()->assertJsonMissingPath('data.0');
            }
        }
    }

    public function test_action_permissions_are_required_even_for_the_project_manager(): void
    {
        $f = $this->fixture();
        $role = $f['manager']->roles()->where('code', 'it_pm')->firstOrFail();
        $role->permissions()->detach(Permission::whereIn('code', [
            'task.create', 'task.assign', 'defect.assign',
        ])->pluck('id'));
        Cache::flush();

        foreach (['task', 'defect'] as $type) {
            $this->actingAs($f['manager'])->getJson($this->url($f['project'], $type))->assertForbidden();
        }
    }

    public function test_project_without_supplier_does_not_expand_to_the_user_directory(): void
    {
        $f = $this->fixture();
        $f['project']->update(['supplier_org_id' => null]);
        $this->actingAs($f['manager'])
            ->getJson($this->url($f['project'], 'defect'))->assertOk()
            ->assertExactJson([
                'code' => 200, 'message' => 'success',
                'data' => [['id' => $f['explicitDeveloper']->id, 'display_name' => $f['explicitDeveloper']->display_name]],
            ]);
        $f['project']->members()->where('user_id', $f['explicitDeveloper']->id)->delete();
        $this->actingAs($f['manager'])
            ->getJson($this->url($f['project'], 'defect'))->assertOk()->assertJsonPath('data', []);
    }

    public function test_authentication_project_and_type_are_required(): void
    {
        $f = $this->fixture();
        $this->getJson($this->url($f['project'], 'task'))->assertUnauthorized();
        $this->actingAs($f['manager'])->getJson('/api/projects/999999/assignee-options?type=task')->assertNotFound();
        $this->actingAs($f['manager'])->getJson($this->url($f['project'], 'directory'))->assertUnprocessable();
        $this->actingAs($f['manager'])->getJson("/api/projects/{$f['project']->id}/assignee-options")->assertUnprocessable();
    }

    private function url(Project $project, string $type): string
    {
        return "/api/projects/{$project->id}/assignee-options?type={$type}";
    }

    private function fixture(): array
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $member = User::factory()->withRole('it_member')->create();
        $supplierPm = User::factory()->withRole('supplier_pm')->create();
        $developer = User::factory()->withRole('supplier_dev')->create();
        $siblingDeveloper = User::factory()->withRole('supplier_dev')->create();
        $explicitDeveloper = User::factory()->withRole('supplier_dev')->create();
        $tester = User::factory()->withRole('supplier_tester')->create();
        $inactive = User::factory()->withRole('supplier_dev')->create(['is_active' => false]);
        $disabled = User::factory()->withRole('supplier_dev')->create(['is_disabled' => true]);
        $outsider = User::factory()->withRole('supplier_dev')->create();
        $root = Organization::create(['name' => 'Supplier root', 'org_type' => 2, 'is_active' => true]);
        $supplier = Organization::create(['name' => 'Delivery team', 'parent_id' => $root->id, 'org_type' => 2]);
        $sibling = Organization::create(['name' => 'Sibling team', 'parent_id' => $root->id, 'org_type' => 2]);
        $otherSupplier = Organization::create(['name' => 'Other supplier', 'org_type' => 2]);
        foreach ([$supplierPm, $developer, $tester, $inactive, $disabled] as $user) {
            $user->organizations()->attach($supplier);
        }
        $siblingDeveloper->organizations()->attach($sibling);
        $outsider->organizations()->attach($otherSupplier);
        $project = Project::factory()->create([
            'manager_id' => $manager->id, 'supplier_org_id' => $supplier->id,
        ]);
        foreach ([$member, $explicitDeveloper] as $user) {
            $project->members()->create([
                'user_id' => $user->id, 'role_in_project' => 'member',
                'assigned_by_id' => $manager->id, 'assigned_at' => now(),
            ]);
        }

        return compact('manager', 'member', 'supplierPm', 'developer', 'siblingDeveloper',
            'explicitDeveloper', 'tester', 'otherSupplier', 'project');
    }
}
