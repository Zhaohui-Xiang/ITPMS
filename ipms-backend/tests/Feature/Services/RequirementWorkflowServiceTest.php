<?php

namespace Tests\Feature\Services;

use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\RequirementStatus;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\RequirementVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\RequirementWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RequirementWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_status_is_lowest_project_delivery_progress(): void
    {
        $requirement = Requirement::factory()->create([
            'status' => RequirementStatus::ASSIGNED->value,
        ]);
        RequirementProject::factory()->for($requirement)->create([
            'delivery_status' => ProjectDeliveryStatus::DEPLOYED,
        ]);
        RequirementProject::factory()->for($requirement)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_DEVELOPMENT,
        ]);

        $status = $this->service()->recalculateAggregateStatus($requirement);

        $this->assertSame(RequirementStatus::IN_DEVELOPMENT, $status);
        $this->assertSame(
            RequirementStatus::IN_DEVELOPMENT->value,
            $requirement->refresh()->status,
        );
    }

    public function test_pending_review_remains_pending_before_approval(): void
    {
        $requirement = Requirement::factory()->withProjects(2)->create([
            'status' => RequirementStatus::PENDING_REVIEW->value,
        ]);
        $requirement->projectLinks()->first()->update([
            'delivery_status' => ProjectDeliveryStatus::IN_TESTING,
        ]);

        $status = $this->service()->recalculateAggregateStatus($requirement);

        $this->assertSame(RequirementStatus::PENDING_REVIEW, $status);
        $this->assertSame(
            RequirementStatus::PENDING_REVIEW->value,
            $requirement->refresh()->status,
        );
    }

    public function test_submit_creates_requirement_and_all_project_links_atomically(): void
    {
        $requester = User::factory()->systemUser()->create();
        $projects = Project::factory()->count(2)->create();

        $requirement = $this->service()->submit([
            'title' => 'Cross-project delivery',
            'description' => 'Deliver the same requirement to both projects.',
            'priority' => 2,
            'requirement_type' => 1,
            'expected_completion_date' => '2026-10-30',
            'project_ids' => $projects->pluck('id')->all(),
        ], $requester);

        $this->assertSame($requester->id, $requirement->submitter_id);
        $this->assertSame($requester->id, $requirement->created_by_id);
        $this->assertSame(RequirementStatus::PENDING_REVIEW->value, $requirement->status);
        $this->assertSame(1, $requirement->version);
        $this->assertEqualsCanonicalizing(
            $projects->pluck('id')->all(),
            $requirement->projectLinks()->pluck('project_id')->all(),
        );
        $this->assertSame(
            [
                ProjectDeliveryStatus::ASSIGNED,
                ProjectDeliveryStatus::ASSIGNED,
            ],
            $requirement->projectLinks()->orderBy('id')->get()->pluck('delivery_status')->all(),
        );
    }

    public function test_approval_initializes_each_project_and_saves_review_audit(): void
    {
        $reviewer = User::factory()->internal()->create();
        $requirement = Requirement::factory()->withProjects(2)->create([
            'status' => RequirementStatus::PENDING_REVIEW->value,
        ]);
        $requirement->projectLinks()->first()->update([
            'delivery_status' => ProjectDeliveryStatus::DEPLOYED,
        ]);

        $result = $this->service()->review(
            $requirement,
            $reviewer,
            'approve',
            'Approved for delivery.',
        );

        $this->assertSame(RequirementStatus::ASSIGNED->value, $result->status);
        $this->assertSame($reviewer->id, $result->reviewer_id);
        $this->assertSame('Approved for delivery.', $result->review_comment);
        $this->assertNotNull($result->reviewed_at);
        $this->assertSame(
            [
                ProjectDeliveryStatus::ASSIGNED,
                ProjectDeliveryStatus::ASSIGNED,
            ],
            $result->projectLinks()->orderBy('id')->get()->pluck('delivery_status')->all(),
        );
    }

    public function test_rejection_keeps_pending_review_and_records_decision(): void
    {
        $reviewer = User::factory()->internal()->create();
        $requirement = Requirement::factory()->withProjects(1)->create([
            'status' => RequirementStatus::PENDING_REVIEW->value,
        ]);

        $result = $this->service()->review(
            $requirement,
            $reviewer,
            'reject',
            'Please clarify the acceptance criteria.',
        );

        $this->assertSame(RequirementStatus::PENDING_REVIEW->value, $result->status);
        $this->assertSame($reviewer->id, $result->reviewer_id);
        $this->assertSame(
            'Please clarify the acceptance criteria.',
            $result->review_comment,
        );
        $this->assertNotNull($result->reviewed_at);
    }

    public function test_rejected_requirement_cannot_be_approved_before_resubmission(): void
    {
        $reviewer = User::factory()->internal()->create();
        $requirement = Requirement::factory()->withProjects(1)->create([
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => $reviewer->id,
            'review_comment' => 'Rejected',
            'reviewed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        $this->service()->review(
            $requirement,
            $reviewer,
            'approve',
            'Approval without requester revision',
        );
    }

    public function test_only_original_requester_can_edit_and_resubmit_rejected_requirement(): void
    {
        $requester = User::factory()->systemUser()->create();
        $other = User::factory()->systemUser()->create();
        $reviewer = User::factory()->internal()->create();
        $requirement = Requirement::factory()->withProjects(1)->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => $reviewer->id,
            'review_comment' => 'Rejected',
            'reviewed_at' => now(),
        ]);

        try {
            $this->service()->resubmit($requirement, $other, ['title' => 'Forbidden edit']);
            $this->fail('A non-requester resubmission should be rejected.');
        } catch (AuthorizationException) {
            $this->assertSame('Rejected', $requirement->refresh()->review_comment);
        }

        $result = $this->service()->resubmit($requirement, $requester, [
            'title' => 'Clarified requirement',
            'description' => 'Acceptance criteria are now explicit.',
        ]);

        $this->assertSame('Clarified requirement', $result->title);
        $this->assertSame(
            'Acceptance criteria are now explicit.',
            $result->description,
        );
    }

    public function test_resubmission_increments_version_creates_one_snapshot_and_clears_decision(): void
    {
        $requester = User::factory()->systemUser()->create();
        $reviewer = User::factory()->internal()->create();
        $oldProject = Project::factory()->create();
        $newProject = Project::factory()->create();
        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'priority' => 3,
            'version' => 3,
            'reviewer_id' => $reviewer->id,
            'review_comment' => 'Rejected',
            'reviewed_at' => now(),
        ]);
        RequirementProject::factory()->for($requirement)->for($oldProject)->create();

        $result = $this->service()->resubmit($requirement, $requester, [
            'title' => 'Revision four',
            'priority' => 1,
            'project_ids' => [$newProject->id],
        ]);

        $this->assertSame(4, $result->version);
        $this->assertNull($result->reviewer_id);
        $this->assertNull($result->review_comment);
        $this->assertNull($result->reviewed_at);
        $this->assertSame(RequirementStatus::PENDING_REVIEW->value, $result->status);
        $this->assertSame([$newProject->id], $result->projectLinks()->pluck('project_id')->all());
        $this->assertSame(1, RequirementVersion::query()
            ->where('requirement_id', $result->id)
            ->where('version_number', 4)
            ->count());

        $snapshot = RequirementVersion::query()
            ->where('requirement_id', $result->id)
            ->firstOrFail();
        $this->assertSame($requester->id, $snapshot->changed_by_id);
        $this->assertSame('Requirement resubmitted', $snapshot->change_summary);
        $this->assertSame(
            ['title', 'priority', 'project_ids'],
            collect($snapshot->changes)->pluck('field')->all(),
        );
    }

    public function test_approval_rolls_back_when_a_real_project_write_fails(): void
    {
        $reviewer = User::factory()->internal()->create();
        $requirement = Requirement::factory()->withProjects(2)->create([
            'status' => RequirementStatus::PENDING_REVIEW->value,
        ]);
        $links = $requirement->projectLinks()->orderBy('id')->get();
        $links->each->update([
            'delivery_status' => ProjectDeliveryStatus::DEPLOYED,
        ]);
        $this->failRequirementProjectWritesFor((int) $links->last()->project_id);

        try {
            $this->service()->review($requirement, $reviewer, 'approve', 'Approved');
            $this->fail('The database trigger should reject a project-side write.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'injected project-side failure',
                $exception->getMessage(),
            );
        }

        $requirement->refresh();
        $this->assertSame(RequirementStatus::PENDING_REVIEW->value, $requirement->status);
        $this->assertNull($requirement->reviewer_id);
        $this->assertNull($requirement->reviewed_at);
        $this->assertSame(
            [
                ProjectDeliveryStatus::DEPLOYED,
                ProjectDeliveryStatus::DEPLOYED,
            ],
            $requirement->projectLinks()->orderBy('id')->get()->pluck('delivery_status')->all(),
        );
    }

    public function test_resubmission_rolls_back_requirement_snapshot_and_links_on_real_insert_failure(): void
    {
        $requester = User::factory()->systemUser()->create();
        $reviewer = User::factory()->internal()->create();
        $oldProject = Project::factory()->create();
        $failingProject = Project::factory()->create();
        $requirement = Requirement::factory()->create([
            'title' => 'Original title',
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'version' => 2,
            'reviewer_id' => $reviewer->id,
            'review_comment' => 'Rejected',
            'reviewed_at' => now(),
        ]);
        RequirementProject::factory()->for($requirement)->for($oldProject)->create();
        $this->failRequirementProjectWritesFor($failingProject->id);

        try {
            $this->service()->resubmit($requirement, $requester, [
                'title' => 'Attempted title',
                'project_ids' => [$oldProject->id, $failingProject->id],
            ]);
            $this->fail('The database trigger should reject the inserted project link.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'injected project-side failure',
                $exception->getMessage(),
            );
        }

        $requirement->refresh();
        $this->assertSame('Original title', $requirement->title);
        $this->assertSame(2, $requirement->version);
        $this->assertSame($reviewer->id, $requirement->reviewer_id);
        $this->assertSame('Rejected', $requirement->review_comment);
        $this->assertSame(0, $requirement->versions()->count());
        $this->assertSame([$oldProject->id], $requirement->projectLinks()->pluck('project_id')->all());
    }

    public function test_project_delivery_only_moves_one_step_and_records_version_history(): void
    {
        $actor = User::factory()->internal()->create();
        $version = ProjectVersion::factory()->create([
            'status' => ProjectVersionStatus::IN_DEVELOPMENT,
        ]);
        $requirement = Requirement::factory()->create([
            'status' => RequirementStatus::ASSIGNED->value,
        ]);
        $link = RequirementProject::factory()
            ->for($requirement)
            ->forVersion($version)
            ->create(['delivery_status' => ProjectDeliveryStatus::ASSIGNED]);

        $result = $this->service()->transitionProjectDelivery(
            $requirement,
            $version->project_id,
            ProjectDeliveryStatus::IN_DEVELOPMENT,
            $actor,
        );

        $this->assertSame(ProjectDeliveryStatus::IN_DEVELOPMENT, $result->delivery_status);
        $this->assertSame(
            RequirementStatus::IN_DEVELOPMENT->value,
            $requirement->refresh()->status,
        );
        $this->assertDatabaseHas('project_version_histories', [
            'project_version_id' => $version->id,
            'event_type' => 'requirement_delivery_status_changed',
            'actor_id' => $actor->id,
        ]);
        $history = $version->histories()->firstOrFail();
        $this->assertNull($history->from_status);
        $this->assertNull($history->to_status);
        $this->assertSame($link->id, $history->metadata['requirement_project_id']);
        $this->assertSame(2, $history->metadata['from_delivery_status']);
        $this->assertSame(3, $history->metadata['to_delivery_status']);
    }

    public function test_project_delivery_rejects_skipped_or_backward_transitions(): void
    {
        $actor = User::factory()->internal()->create();
        $link = RequirementProject::factory()->create([
            'delivery_status' => ProjectDeliveryStatus::IN_DEVELOPMENT,
        ]);

        foreach ([ProjectDeliveryStatus::PENDING_DEPLOY, ProjectDeliveryStatus::ASSIGNED] as $target) {
            try {
                $this->service()->transitionProjectDelivery(
                    $link->requirement,
                    $link->project_id,
                    $target,
                    $actor,
                );
                $this->fail('A non-adjacent transition should be rejected.');
            } catch (ValidationException) {
                $this->assertSame(
                    ProjectDeliveryStatus::IN_DEVELOPMENT,
                    $link->refresh()->delivery_status,
                );
            }
        }

        $this->assertDatabaseCount('project_version_histories', 0);
    }

    public function test_status_endpoint_requires_project_and_updates_only_that_project_side(): void
    {
        $actor = $this->userWithPermission('it_pm', 'requirement.transition');
        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();
        DB::table('project_members')->insert([
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'role_in_project' => 'pm',
            'assigned_at' => now(),
        ]);
        $requirement = Requirement::factory()->create([
            'status' => RequirementStatus::ASSIGNED->value,
        ]);
        $target = RequirementProject::factory()->for($requirement)->for($project)->create();
        $other = RequirementProject::factory()->for($requirement)->for($otherProject)->create();

        $this->actingAs($actor)->postJson("/api/requirements/{$requirement->id}/status", [
            'status' => ProjectDeliveryStatus::IN_DEVELOPMENT->value,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 422);

        $this->actingAs($actor)->postJson("/api/requirements/{$requirement->id}/status", [
            'project_id' => $project->id,
            'status' => ProjectDeliveryStatus::IN_DEVELOPMENT->value,
        ])->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.status', RequirementStatus::ASSIGNED->value);

        $this->assertSame(ProjectDeliveryStatus::IN_DEVELOPMENT, $target->refresh()->delivery_status);
        $this->assertSame(ProjectDeliveryStatus::ASSIGNED, $other->refresh()->delivery_status);
    }

    public function test_resubmit_endpoint_applies_original_requester_revision(): void
    {
        $requester = $this->userWithPermission('requester', 'requirement.edit');
        $reviewer = User::factory()->internal()->create();
        $project = Project::factory()->create();
        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => $reviewer->id,
            'review_comment' => 'Rejected',
            'reviewed_at' => now(),
        ]);
        RequirementProject::factory()->for($requirement)->for($project)->create();

        $this->actingAs($requester)
            ->postJson("/api/requirements/{$requirement->id}/resubmit", [
                'title' => 'API resubmission',
                'project_ids' => [$project->id],
            ])->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.title', 'API resubmission')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.reviewer_id', null);
    }

    private function service(): RequirementWorkflowService
    {
        return app(RequirementWorkflowService::class);
    }

    private function failRequirementProjectWritesFor(int $projectId): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION fail_selected_requirement_project_write()
            RETURNS trigger AS \$\$
            BEGIN
                IF NEW.project_id = {$projectId} THEN
                    RAISE EXCEPTION 'injected project-side failure';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fail_selected_requirement_project_write
            BEFORE INSERT OR UPDATE ON requirement_project
            FOR EACH ROW EXECUTE FUNCTION fail_selected_requirement_project_write();
            SQL);
    }

    private function userWithPermission(string $roleCode, string $permissionCode): User
    {
        $user = User::factory()->withRole($roleCode)->create();
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        $permission = Permission::query()->firstOrCreate(
            ['code' => $permissionCode],
            [
                'name' => $permissionCode,
                'module' => 'requirement',
                'action' => 'test',
            ],
        );
        $role->permissions()->syncWithoutDetaching($permission);

        return $user;
    }
}
