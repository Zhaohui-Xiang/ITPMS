<?php

namespace Tests\Feature\Api;

use App\Enums\DefectStatus;
use App\Models\Defect;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_only_admin_and_assigned_it_pm_can_manage_members(): void
    {
        [$pm, $project, $tester] = $this->fixture();
        $this->getJson("/api/projects/{$project->id}/members")->assertUnauthorized();
        foreach ([User::factory()->withRole('it_pm')->create(), $tester] as $actor) {
            $this->actingAs($actor)->getJson("/api/projects/{$project->id}/members")->assertForbidden();
            $this->getJson("/api/projects/{$project->id}/member-options")->assertForbidden();
            $this->postJson("/api/projects/{$project->id}/members", ['user_id' => $tester->id])->assertForbidden();
            $this->deleteJson("/api/projects/{$project->id}/members/{$pm->id}")->assertForbidden();
        }
        foreach ([$pm, User::factory()->superAdmin()->create()] as $actor) {
            $this->actingAs($actor)->getJson("/api/projects/{$project->id}/members")->assertOk();
            $this->assertContains('manage_members', $this->getJson("/api/projects/{$project->id}")->json('data.allowed_actions'));
        }
    }

    public function test_candidates_and_writes_exclude_disabled_requesters_and_foreign_suppliers(): void
    {
        [$pm, $project, $tester] = $this->fixture();
        $internal = User::factory()->withRole('it_member')->create();
        $disabled = User::factory()->withRole('it_member')->create(['is_disabled' => true]);
        $inactive = User::factory()->withRole('it_member')->create(['is_active' => false]);
        $requester = User::factory()->withRole('requester')->create();
        $outsider = User::factory()->withRole('supplier_tester')->create();
        $options = $this->actingAs($pm)->getJson("/api/projects/{$project->id}/member-options")
            ->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$internal->id, $tester->id], array_column($options, 'id'));
        foreach ($options as $option) {
            $this->assertSame(['id', 'display_name'], array_keys($option));
        }
        foreach ([$disabled, $inactive, $requester, $outsider] as $user) {
            $this->postJson("/api/projects/{$project->id}/members", ['user_id' => $user->id])
                ->assertUnprocessable();
        }
        $this->assertDatabaseCount('project_members', 0);
    }

    public function test_add_is_idempotent_audited_and_does_not_grant_global_roles(): void
    {
        [$pm, $project, $tester] = $this->fixture();
        $roles = $tester->roles()->pluck('code')->all();
        $this->actingAs($pm)->postJson("/api/projects/{$project->id}/members", [
            'user_id' => $tester->id, 'role_in_project' => 'pm', 'roles' => ['super_admin'],
        ])->assertOk();
        $this->postJson("/api/projects/{$project->id}/members", ['user_id' => $tester->id])->assertOk();
        $this->assertDatabaseCount('project_members', 1);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id, 'user_id' => $tester->id,
            'role_in_project' => 'member', 'assigned_by_id' => $pm->id,
        ]);
        $this->assertSame($roles, $tester->roles()->pluck('code')->all());
        $this->assertDatabaseHas('audit_logs', ['module' => 1, 'target_id' => (string) $project->id]);
        $this->getJson("/api/projects/{$project->id}/members")
            ->assertOk()->assertJsonFragment(['user_id' => $tester->id]);
        $this->deleteJson("/api/projects/{$project->id}/members/{$pm->id}")->assertStatus(409);
        $this->deleteJson("/api/projects/{$project->id}/members/{$tester->id}")->assertOk();
        $this->assertDatabaseCount('project_members', 0);
        $project->update(['status' => 3]);
        $this->postJson("/api/projects/{$project->id}/members", ['user_id' => $tester->id])->assertForbidden();
    }

    public function test_managed_membership_enables_retest_without_relaxing_policy(): void
    {
        [$pm, $project, $tester] = $this->fixture();
        $requirement = Requirement::factory()->create();
        RequirementProject::factory()->for($requirement)->for($project)->create();
        $defect = Defect::factory()->create([
            'requirement_id' => $requirement->id, 'project_id' => $project->id,
            'status' => DefectStatus::PENDING_RETEST->value,
        ]);
        $this->actingAs($tester)->postJson("/api/defects/{$defect->id}/verify", ['result' => 'pass'])->assertForbidden();
        $this->actingAs($pm)->postJson("/api/projects/{$project->id}/members", ['user_id' => $tester->id])->assertOk();
        $this->actingAs($tester)->postJson("/api/defects/{$defect->id}/verify", ['result' => 'pass'])->assertOk();
    }

    public function test_inactive_or_disabled_managers_and_archived_projects_cannot_manage_members(): void
    {
        [$pm, $project, $tester] = $this->fixture();
        foreach ([$pm, User::factory()->superAdmin()->create()] as $actor) {
            foreach ([['is_active' => false], ['is_disabled' => true]] as $state) {
                $actor->update(['is_active' => true, 'is_disabled' => false, ...$state]);
                // 禁用用户的会话被 EnsureUserIsActive 即时销毁，每个请求都需要重新建立认证态
                $this->actingAs($actor)->getJson("/api/projects/{$project->id}/members")->assertForbidden();
                $this->actingAs($actor)->getJson("/api/projects/{$project->id}/member-options")->assertForbidden();
                $this->actingAs($actor)->postJson("/api/projects/{$project->id}/members", ['user_id' => $tester->id])->assertForbidden();
                $this->actingAs($actor)->deleteJson("/api/projects/{$project->id}/members/{$tester->id}")->assertForbidden();
            }
            $actor->update(['is_active' => true, 'is_disabled' => false]);
        }
        $project->update(['status' => 3]);
        foreach ([$pm, User::factory()->superAdmin()->create()] as $actor) {
            $this->actingAs($actor)->getJson("/api/projects/{$project->id}/members")->assertForbidden();
            $this->postJson("/api/projects/{$project->id}/members", ['user_id' => $tester->id])->assertForbidden();
            $this->deleteJson("/api/projects/{$project->id}/members/{$tester->id}")->assertForbidden();
            $this->assertNotContains('manage_members', $this->getJson("/api/projects/{$project->id}")->json('data.allowed_actions'));
        }
        $this->assertDatabaseCount('project_members', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_add_and_remove_are_idempotent_and_audit_only_actual_changes(): void
    {
        [$pm, $project, $tester] = $this->fixture();
        $this->actingAs($pm);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson("/api/projects/{$project->id}/members", ['user_id' => $tester->id])->assertOk();
        }
        $this->assertDatabaseCount('audit_logs', 1);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->deleteJson("/api/projects/{$project->id}/members/{$tester->id}")->assertOk();
        }
        $this->assertDatabaseCount('project_members', 0);
        $audits = DB::table('audit_logs')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        foreach (['member_added', 'member_removed'] as $index => $operation) {
            $this->assertSame($pm->id, $audits[$index]->user_id);
            $this->assertSame((string) $project->id, $audits[$index]->target_id);
            $this->assertSame(1, $audits[$index]->module);
            $this->assertSame(2, $audits[$index]->action_type);
            $this->assertSame('project', $audits[$index]->target_type);
            $detail = json_decode($audits[$index]->detail, true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(2, $detail);
            $this->assertSame($operation, $detail['operation']);
            $this->assertSame($tester->id, $detail['user_id']);
        }
    }

    public function test_add_and_remove_roll_back_when_audit_insert_fails(): void
    {
        [$pm, $project, $tester] = $this->fixture();
        $existing = $project->members()->create(['user_id' => $pm->id, 'role_in_project' => 'pm']);
        $member = User::factory()->withRole('it_member')->create();
        $project->members()->create(['user_id' => $member->id, 'role_in_project' => 'member']);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fail_membership_audit() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'injected membership audit failure';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER fail_membership_audit BEFORE INSERT ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION fail_membership_audit();
            SQL);
        $this->actingAs($pm)->postJson("/api/projects/{$project->id}/members", ['user_id' => $tester->id])
            ->assertInternalServerError();
        $this->assertDatabaseMissing('project_members', ['project_id' => $project->id, 'user_id' => $tester->id]);
        $this->deleteJson("/api/projects/{$project->id}/members/{$member->id}")->assertInternalServerError();
        $this->assertDatabaseHas('project_members', ['project_id' => $project->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('project_members', ['id' => $existing->id, 'role_in_project' => 'pm']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_supplier_subtree_candidates_do_not_gain_membership_management(): void
    {
        [$pm, $project, $tester] = $this->fixture();
        $child = Organization::create(['name' => 'Delivery team', 'org_type' => 2, 'is_active' => true, 'parent_id' => $project->supplier_org_id]);
        $supplierPm = User::factory()->withRole('supplier_pm')->create();
        $supplierPm->organizations()->attach($child);
        $this->actingAs($pm)->getJson("/api/projects/{$project->id}/member-options")
            ->assertOk()->assertJsonFragment(['id' => $supplierPm->id, 'display_name' => $supplierPm->display_name]);
        $this->postJson("/api/projects/{$project->id}/members", ['user_id' => $supplierPm->id])->assertOk();
        $this->actingAs($supplierPm)->postJson("/api/projects/{$project->id}/members", ['user_id' => $tester->id])->assertForbidden();
        $this->assertNotContains('manage_members', $this->getJson("/api/projects/{$project->id}")->json('data.allowed_actions'));
    }

    public function test_assigned_non_pm_and_supplier_pm_cannot_manage_members(): void
    {
        [$pm, $project, $tester] = $this->fixture();
        foreach (['it_member', 'supplier_pm'] as $role) {
            $actor = User::factory()->withRole($role)->create();
            $project->update(['manager_id' => $actor->id]);
            $this->actingAs($actor)->getJson("/api/projects/{$project->id}/members")->assertForbidden();
            $this->getJson("/api/projects/{$project->id}/member-options")->assertForbidden();
            $this->postJson("/api/projects/{$project->id}/members", ['user_id' => $tester->id])->assertForbidden();
            $this->deleteJson("/api/projects/{$project->id}/members/{$tester->id}")->assertForbidden();
        }
        $this->assertDatabaseCount('project_members', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function fixture(): array
    {
        $pm = User::factory()->withRole('it_pm')->create();
        $org = Organization::create(['name' => 'Delivery supplier', 'org_type' => 2, 'is_active' => true]);
        $tester = User::factory()->withRole('supplier_tester')->create();
        $tester->organizations()->attach($org);
        $project = Project::factory()->create(['manager_id' => $pm->id, 'supplier_org_id' => $org->id, 'status' => 1]);

        return [$pm, $project, $tester];
    }
}
