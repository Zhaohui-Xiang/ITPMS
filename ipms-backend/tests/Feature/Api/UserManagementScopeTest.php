<?php
namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class UserManagementScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        foreach (['user.view', 'user.create', 'user.edit'] as $code) {
            $permission = \App\Models\Permission::firstOrCreate(['code' => $code], [
                'name' => $code, 'module' => 'organization', 'action' => 'create',
            ]);
            Role::where('code', 'supplier_pm')->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    public function test_supplier_cannot_create_privileged_role_system_user_or_outside_member(): void
    {
        [$pm, $team] = $this->team();
        $other = Organization::create(['name' => 'Other', 'org_type' => 2]);
        foreach ([
            ['role_ids' => [Role::where('code', 'super_admin')->value('id')]],
            ['user_type' => 3],
            ['organization_ids' => [$other->id]],
            ['organization_ids' => []],
        ] as $override) {
            $payload = array_replace($this->payload($team), $override);
            $this->actingAs($pm)->postJson('/api/users', $payload)->assertForbidden();
            $this->assertDatabaseMissing('users', ['username' => $payload['username']]);
        }
    }

    public function test_superadmin_can_create_and_edit_a_member(): void
    {
        [, $team] = $this->team();
        $pm = User::factory()->superAdmin()->create();
        $id = $this->actingAs($pm)->postJson('/api/users', $this->payload($team))
            ->assertCreated()->json('data.id');
        $this->getJson("/api/users/{$id}")->assertOk();
        $this->putJson("/api/users/{$id}", ['display_name' => 'Updated'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $id, 'display_name' => 'Updated', 'must_change_password' => true]);
    }

    public function test_supplier_cannot_read_or_edit_another_team_or_internal_user(): void
    {
        [$pm, $team] = $this->team();
        $other = User::factory()->withRole('supplier_dev')->create();
        $admin = User::factory()->superAdmin()->create();
        $admin->organizations()->attach($team);
        foreach ([$other, $admin] as $target) {
            $this->actingAs($pm)->getJson("/api/users/{$target->id}")->assertForbidden();
            $this->putJson("/api/users/{$target->id}", ['email' => 'changed@example.test'])->assertForbidden();
            $this->assertSame($target->email, $target->fresh()->email);
        }
        $this->getJson('/api/users')->assertForbidden();
    }

    private function team(): array
    {
        $pm = User::factory()->withRole('supplier_pm')->create();
        $team = Organization::create(['name' => 'Delivery', 'org_type' => 2]);
        $pm->organizations()->attach($team);
        return [$pm, $team];
    }

    private function payload(Organization $team): array
    {
        return [
            'username' => 'new.member', 'password' => 'test-only-password',
            'user_type' => 2, 'role_ids' => [Role::where('code', 'supplier_dev')->value('id')],
            'organization_ids' => [$team->id],
        ];
    }
}
