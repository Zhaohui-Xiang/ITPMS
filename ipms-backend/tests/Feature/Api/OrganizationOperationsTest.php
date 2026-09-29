<?php
namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_crud_and_membership_are_persisted_with_audit(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $member = User::factory()->internal()->create();
        $id = $this->actingAs($admin)->postJson('/api/organizations', ['name' => 'IT', 'org_type' => 1])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/organizations/{$id}/users", ['user_ids' => [$member->id]])->assertOk();
        $this->getJson('/api/organizations?org_type=1')->assertOk()->assertJsonPath('data.0.users.0.id', $member->id);
        $this->deleteJson("/api/organizations/{$id}")->assertUnprocessable();
        $this->deleteJson("/api/organizations/{$id}/users/{$member->id}")->assertOk();
        $this->putJson("/api/organizations/{$id}", ['name' => 'IT office'])->assertOk();
        $this->deleteJson("/api/organizations/{$id}")->assertOk();
        // 组织模块审计独立编码（8=组织），不再错挂"系统与发布"
        $this->assertDatabaseHas('audit_logs', ['module' => 8, 'action_type' => 3, 'target_id' => (string) $id]);
    }

    public function test_duplicate_sibling_org_name_is_rejected(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $parent = Organization::create(['name' => 'IT 部门', 'org_type' => 1]);

        $this->actingAs($admin)->postJson('/api/organizations', [
            'name' => '平台组', 'org_type' => 1, 'parent_id' => $parent->id,
        ])->assertCreated();

        // 同级同名 → 422
        $this->postJson('/api/organizations', [
            'name' => '平台组', 'org_type' => 1, 'parent_id' => $parent->id,
        ])->assertUnprocessable()
            ->assertJsonPath('error_code', 'ORGANIZATION_NAME_DUPLICATE');

        // 不同父级同名 → 允许
        $other = Organization::create(['name' => '研发中心', 'org_type' => 1]);
        $this->postJson('/api/organizations', [
            'name' => '平台组', 'org_type' => 1, 'parent_id' => $other->id,
        ])->assertCreated();
    }

    public function test_non_admin_cannot_read_org_directory_or_manage_nodes(): void
    {
        $user = User::factory()->withRole('requester')->create();
        $org = Organization::create(['name' => 'Private org', 'org_type' => 1]);
        $this->actingAs($user)->getJson('/api/organizations?org_type=1')->assertForbidden();
        $this->getJson("/api/organizations/{$org->id}/children")->assertForbidden();
        $this->postJson('/api/organizations', ['name' => 'X', 'org_type' => 1])->assertForbidden();
    }

    public function test_parent_and_member_types_must_match(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $org = Organization::create(['name' => 'IT', 'org_type' => 1]);
        $supplier = User::factory()->withRole('supplier_dev')->create();
        $this->actingAs($admin)->postJson('/api/organizations', [
            'name' => 'Invalid', 'org_type' => 2, 'parent_id' => $org->id,
        ])->assertUnprocessable();
        $this->postJson("/api/organizations/{$org->id}/users", ['user_ids' => [$supplier->id]])->assertUnprocessable();
    }
}
