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

    public function test_superadmin_can_create_internal_user(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->postJson('/api/users', [
            'username' => 'new.itpm',
            'password' => 'test-only-password',
            'user_type' => 1,
            'role_ids' => [Role::where('code', 'it_pm')->value('id')],
        ])
            ->assertCreated()
            ->assertJsonPath('data.user_type', 1);
        $this->assertDatabaseHas('users', ['username' => 'new.itpm', 'user_type' => 1]);
    }

    public function test_disable_enable_cycle_restores_login(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $member = User::factory()->withRole('it_member')->create();

        $this->actingAs($admin)->postJson("/api/users/{$member->id}/disable")->assertOk();
        $this->assertTrue($member->refresh()->is_disabled);

        $this->actingAs($admin)->postJson("/api/users/{$member->id}/enable")->assertOk();
        $this->assertFalse($member->refresh()->is_disabled);
    }

    public function test_delete_soft_deletes_and_blocks_login(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $member = User::factory()->withRole('it_member')->create([
            'username' => 'doomed.member', 'password' => 'Secret123',
        ]);

        $this->actingAs($admin)->deleteJson("/api/users/{$member->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $member->id]);
        $this->assertTrue($member->refresh()->is_disabled);

        // 列表不再出现；登录被拒
        $list = $this->actingAs($admin)->getJson('/api/users')->assertOk();
        $this->assertNotContains($member->id, collect($list->json('data.items'))->pluck('id'));
        $this->postJson('/api/login', ['username' => 'doomed.member', 'password' => 'Secret123'])
            ->assertUnprocessable();

        // 最后超管保护：自己删自己 422；存在其他有效超管时可删；删完后最后超管被保护
        $other = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->deleteJson("/api/users/{$admin->id}")->assertUnprocessable();
        $this->actingAs($other)->deleteJson("/api/users/{$admin->id}")->assertOk();
        $this->actingAs($other)->deleteJson("/api/users/{$other->id}")->assertUnprocessable();
    }

    public function test_update_rejects_role_and_organization_fields_explicitly(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $member = User::factory()->withRole('it_member')->create();

        $this->actingAs($admin)
            ->putJson("/api/users/{$member->id}", [
                'role_ids' => [Role::where('code', 'it_pm')->value('id')],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');

        $this->actingAs($admin)
            ->putJson("/api/users/{$member->id}", ['organization_ids' => [1]])
            ->assertUnprocessable();
    }
}
