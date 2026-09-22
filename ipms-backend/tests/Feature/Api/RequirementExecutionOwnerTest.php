<?php

namespace Tests\Feature\Api;

use App\Enums\RequirementStatus;
use App\Exceptions\DomainConflictException;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\User;
use App\Services\RequirementExecutionOwners;
use App\Services\RequirementWorkflowService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RequirementExecutionOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_manager_can_select_scoped_owner_and_persist_a_revision(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $outsider = User::factory()->withRole('it_member')->create();
        $disabled = User::factory()->withRole('it_member')->create(['is_disabled' => true]);
        $project->members()->create(['user_id' => $disabled->id, 'role_in_project' => 'member']);
        $this->actingAs($pm)->getJson("/api/requirements/{$requirement->id}/execution-owner-options")
            ->assertOk()->assertJsonPath('data', [['id' => $pm->id, 'display_name' => $pm->display_name]]);
        foreach ([$outsider, $disabled] as $user) {
            $this->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => $user->id])
                ->assertUnprocessable();
        }
        $this->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => $pm->id, 'version' => 1])
            ->assertOk()->assertJsonPath('data.dev_lead.id', $pm->id)->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('requirement_versions', ['requirement_id' => $requirement->id, 'version_number' => 2]);
        $this->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => null, 'version' => 1])
            ->assertStatus(409)->assertJsonPath('error_code', 'STALE_VERSION');
        $this->assertSame($pm->id, $requirement->fresh()->dev_lead_id);
    }

    public function test_requester_and_member_cannot_assign_even_through_existing_update_api(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $requester = User::findOrFail($requirement->submitter_id);
        $member = User::factory()->withRole('it_member')->create();
        $project->members()->create(['user_id' => $member->id, 'role_in_project' => 'member']);
        foreach ([$requester, $member] as $user) {
            $this->actingAs($user)->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => $pm->id])->assertForbidden();
            $this->getJson("/api/requirements/{$requirement->id}/execution-owner-options")->assertForbidden();
        }
        $this->assertNull($requirement->fresh()->dev_lead_id);
        $this->assertDatabaseCount('requirement_versions', 0);
    }

    public function test_execution_owner_must_cover_all_linked_projects(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $other = Project::factory()->create();
        RequirementProject::factory()->for($requirement)->for($other)->create();
        $this->actingAs($pm)->getJson("/api/requirements/{$requirement->id}/execution-owner-options")
            ->assertOk()->assertJsonPath('data', []);
        $this->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => $pm->id])->assertUnprocessable();
        $other->members()->create(['user_id' => $pm->id, 'role_in_project' => 'member']);
        $this->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => $pm->id])->assertOk();
    }

    public function test_requester_cannot_clear_noop_or_resubmit_owner_assignment(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $requirement->update(['dev_lead_id' => $pm->id]);
        $requester = User::findOrFail($requirement->submitter_id);
        $this->actingAs($requester);
        foreach ([null, $pm->id] as $ownerId) {
            $this->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => $ownerId, 'title' => 'Forbidden'])->assertForbidden();
        }
        $requirement->update(['reviewer_id' => $pm->id, 'review_comment' => 'Rejected', 'reviewed_at' => now()]);
        $this->postJson("/api/requirements/{$requirement->id}/resubmit", ['title' => 'Forbidden', 'dev_lead_id' => null])->assertForbidden();
        $this->assertNotContains('assign_owner', $this->getJson("/api/requirements/{$requirement->id}")->json('data.allowed_actions'));
        $this->assertSame($pm->id, $requirement->fresh()->dev_lead_id);
        $this->assertSame(1, $requirement->fresh()->version);
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_owner_candidates_exclude_inactive_requesters_and_test_only_staff(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $inactive = User::factory()->withRole('it_member')->create(['is_active' => false]);
        $tester = User::factory()->withRole('supplier_tester')->create();
        $requester = User::findOrFail($requirement->submitter_id);
        foreach ([$inactive, $tester, $requester] as $candidate) {
            $project->members()->create(['user_id' => $candidate->id, 'role_in_project' => 'member']);
            $this->actingAs($pm)->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => $candidate->id])->assertUnprocessable();
        }
        $this->getJson("/api/requirements/{$requirement->id}/execution-owner-options")
            ->assertOk()->assertJsonPath('data', [['id' => $pm->id, 'display_name' => $pm->display_name]]);
        $this->assertContains('assign_owner', $this->getJson("/api/requirements/{$requirement->id}")->json('data.allowed_actions'));
    }

    public function test_owner_save_is_audited_and_stale_write_has_no_side_effects(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $this->actingAs($pm)->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => $pm->id, 'version' => 1])->assertOk();
        $revision = $requirement->versions()->sole();
        $this->assertSame($pm->id, $revision->changed_by_id);
        $this->assertSame(['dev_lead_id'], array_column($revision->changes, 'field'));
        $audit = DB::table('audit_logs')->sole();
        $this->assertSame(2, $audit->module);
        $this->assertSame($pm->id, $audit->user_id);
        $this->assertSame((string) $requirement->id, $audit->target_id);
        $this->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => null, 'version' => 1])
            ->assertStatus(409)->assertJsonPath('error_code', 'STALE_VERSION');
        $this->assertSame(2, $requirement->fresh()->version);
        $this->assertSame($pm->id, $requirement->fresh()->dev_lead_id);
        $this->assertDatabaseCount('requirement_versions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => null, 'version' => 2])
            ->assertOk()->assertJsonPath('data.dev_lead_id', null)->assertJsonPath('data.version', 3);
    }

    public function test_resubmit_rejects_stale_owner_write_without_side_effects(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $requirement->update([
            'submitter_id' => $pm->id, 'version' => 2,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => $pm->id, 'review_comment' => 'Rejected', 'reviewed_at' => now(),
        ]);

        $this->actingAs($pm)->postJson("/api/requirements/{$requirement->id}/resubmit", [
            'dev_lead_id' => $pm->id, 'version' => 1,
        ])->assertConflict()->assertJsonPath('error_code', 'STALE_VERSION');

        $this->assertNull($requirement->fresh()->dev_lead_id);
        $this->assertSame(2, $requirement->fresh()->version);
        $this->assertTrue($requirement->fresh()->isRejectedForResubmission());
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->postJson("/api/requirements/{$requirement->id}/resubmit", [
            'dev_lead_id' => $pm->id, 'version' => 2,
        ])->assertOk()->assertJsonPath('data.dev_lead_id', $pm->id)->assertJsonPath('data.version', 3);

        $this->assertFalse($requirement->fresh()->isRejectedForResubmission());
        $this->assertDatabaseCount('requirement_versions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_service_resubmit_checks_version_against_the_locked_requirement(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $requirement->update([
            'submitter_id' => $pm->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => $pm->id, 'review_comment' => 'Rejected', 'reviewed_at' => now(),
        ]);
        Requirement::whereKey($requirement->id)->update(['version' => 2]);
        $this->assertSame(1, $requirement->version);

        try {
            app(RequirementWorkflowService::class)->resubmit($requirement, $pm, [
                'dev_lead_id' => $pm->id, 'version' => 1,
            ]);
            $this->fail('Resubmission must reject a stale version even when the caller model is stale.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('STALE_VERSION', $exception->errorCode);
        }

        $this->assertNull($requirement->fresh()->dev_lead_id);
        $this->assertSame(2, $requirement->fresh()->version);
        $this->assertTrue($requirement->fresh()->isRejectedForResubmission());
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_owner_assignment_and_revision_roll_back_when_audit_insert_fails(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fail_owner_audit() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'injected owner audit failure';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER fail_owner_audit BEFORE INSERT ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION fail_owner_audit();
            SQL);
        $this->actingAs($pm)->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => $pm->id, 'version' => 1])
            ->assertInternalServerError();
        $this->assertNull($requirement->fresh()->dev_lead_id);
        $this->assertSame(1, $requirement->fresh()->version);
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_stale_loaded_project_scope_is_revalidated_during_assignment(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $requirement->load('projects');
        RequirementProject::factory()->for($requirement)->create();
        try {
            app(\App\Services\RequirementWorkflowService::class)->update($requirement, $pm, ['dev_lead_id' => $pm->id]);
            $this->fail('Assignment must validate against every currently linked project.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('dev_lead_id', $exception->errors());
        }
        $this->assertNull($requirement->fresh()->dev_lead_id);
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_combined_edit_rejects_owner_without_access_to_new_project_over_http(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $requirement->update(['submitter_id' => $pm->id, 'status' => RequirementStatus::PENDING_REVIEW->value]);
        $newProject = Project::factory()->create();

        $this->actingAs($pm)->putJson("/api/requirements/{$requirement->id}", [
            'dev_lead_id' => $pm->id,
            'project_ids' => [$project->id, $newProject->id],
            'version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('dev_lead_id');

        $this->assertSame([$project->id], $requirement->projectLinks()->pluck('project_id')->all());
        $this->assertNull($requirement->fresh()->dev_lead_id);
        $this->assertSame(1, $requirement->fresh()->version);
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_combined_edit_revalidates_effective_project_scope_in_service(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $requirement->update(['submitter_id' => $pm->id, 'status' => RequirementStatus::PENDING_REVIEW->value]);
        $newProject = Project::factory()->create();
        $requirement->load('projects');

        try {
            app(RequirementWorkflowService::class)->update($requirement, $pm, [
                'dev_lead_id' => $pm->id,
                'project_ids' => [$project->id, $newProject->id],
            ]);
            $this->fail('Owner must cover the effective project scope.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dev_lead_id', $exception->errors());
        }

        $this->assertSame([$project->id], $requirement->projectLinks()->pluck('project_id')->all());
        $this->assertNull($requirement->fresh()->dev_lead_id);
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_combined_edit_can_remove_inaccessible_project_and_assign_owner(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $requirement->update(['submitter_id' => $pm->id, 'status' => RequirementStatus::PENDING_REVIEW->value]);
        RequirementProject::factory()->for($requirement)->create();

        $this->actingAs($pm)->putJson("/api/requirements/{$requirement->id}", [
            'dev_lead_id' => $pm->id, 'project_ids' => [$project->id], 'version' => 1,
        ])->assertOk()->assertJsonPath('data.dev_lead_id', $pm->id)->assertJsonPath('data.version', 2);

        $this->assertSame([$project->id], $requirement->projectLinks()->pluck('project_id')->all());
        $this->assertSame(['dev_lead_id', 'project_ids'], array_column($requirement->versions()->sole()->changes, 'field'));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_service_rechecks_candidate_state_after_earlier_eligibility_check(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        foreach ([['is_active' => false], ['is_disabled' => true]] as $state) {
            $candidate = User::factory()->withRole('it_member')->create();
            $project->members()->create(['user_id' => $candidate->id, 'role_in_project' => 'member']);
            $this->assertTrue(app(RequirementExecutionOwners::class)->eligible($candidate, $requirement));
            User::whereKey($candidate->id)->update($state);

            try {
                app(RequirementWorkflowService::class)->update($requirement, $pm, ['dev_lead_id' => $candidate->id]);
                $this->fail('Owner eligibility must be read again inside the transaction.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('dev_lead_id', $exception->errors());
            }
        }
        $this->assertNull($requirement->fresh()->dev_lead_id);
        $this->assertSame(1, $requirement->fresh()->version);
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_service_loads_full_current_project_attributes_for_owner_validation(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $requirement->load('projects:id,name');
        app(RequirementWorkflowService::class)->update($requirement, $pm, ['dev_lead_id' => $pm->id]);
        $this->assertSame($pm->id, $requirement->fresh()->dev_lead_id);
        $this->assertSame(2, $requirement->fresh()->version);
    }

    public function test_service_rejects_owner_when_no_projects_are_linked(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $this->deleteRequirementProjectsForTest($requirement->projectLinks()->pluck('id'));
        $admin = User::factory()->superAdmin()->create();
        try {
            app(RequirementWorkflowService::class)->update($requirement, $admin, ['dev_lead_id' => $pm->id]);
            $this->fail('An execution owner requires project scope.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dev_lead_id', $exception->errors());
        }
        $this->assertNull($requirement->fresh()->dev_lead_id);
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_direct_service_requester_cannot_write_owner_on_update_or_resubmit(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $requester = User::findOrFail($requirement->submitter_id);
        foreach (['update', 'resubmit'] as $method) {
            if ($method === 'resubmit') {
                $requirement->update([
                    'status' => RequirementStatus::PENDING_REVIEW->value,
                    'reviewer_id' => $pm->id, 'review_comment' => 'Rejected', 'reviewed_at' => now(),
                ]);
            }
            foreach ([null, $pm->id] as $ownerId) {
                try {
                    app(RequirementWorkflowService::class)->{$method}($requirement, $requester, ['dev_lead_id' => $ownerId]);
                    $this->fail('Requester owner writes must not bypass authorization through the service.');
                } catch (AuthorizationException) {
                    $this->assertNull($requirement->fresh()->dev_lead_id);
                }
            }
        }
        $this->assertSame(1, $requirement->fresh()->version);
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_supplier_delivery_owner_must_belong_to_every_project_supplier_tree(): void
    {
        [$pm, $project, $requirement] = $this->fixture();
        $supplier = Organization::create(['name' => 'Owner supplier', 'org_type' => 2, 'is_active' => true]);
        $child = Organization::create(['name' => 'Delivery team', 'org_type' => 2, 'is_active' => true, 'parent_id' => $supplier->id]);
        $project->update(['supplier_org_id' => $supplier->id]);
        $other = Project::factory()->create(['supplier_org_id' => $supplier->id]);
        RequirementProject::factory()->for($requirement)->for($other)->create();
        $developer = User::factory()->withRole('supplier_dev')->create();
        $developer->organizations()->attach($child);
        $foreign = User::factory()->withRole('supplier_dev')->create();
        foreach ([$project, $other] as $linkedProject) {
            $linkedProject->members()->create(['user_id' => $foreign->id, 'role_in_project' => 'member']);
        }

        $this->actingAs($pm)->getJson("/api/requirements/{$requirement->id}/execution-owner-options")
            ->assertOk()->assertJsonPath('data', [['id' => $developer->id, 'display_name' => $developer->display_name]]);
        $this->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => $foreign->id])->assertUnprocessable();
        $this->putJson("/api/requirements/{$requirement->id}", ['dev_lead_id' => $developer->id])->assertOk();
        $this->assertSame($developer->id, $requirement->fresh()->dev_lead_id);
    }

    private function fixture(): array
    {
        $pm = User::factory()->withRole('it_pm')->create();
        $requester = User::factory()->withRole('requester')->create();
        $project = Project::factory()->create(['manager_id' => $pm->id, 'supplier_org_id' => null, 'status' => 1]);
        $requirement = Requirement::factory()->create(['submitter_id' => $requester->id, 'version' => 1, 'dev_lead_id' => null]);
        RequirementProject::factory()->for($requirement)->for($project)->create();

        return [$pm, $project, $requirement];
    }
}
