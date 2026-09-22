<?php

namespace Tests\Feature\Services;

use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\RequirementStatus;
use App\Exceptions\DomainConflictException;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\RequirementVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\RequirementWorkflowService;
use App\Services\Results\RequirementUpdateResult;
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
        $this->updateRequirementProjectForTest(
            $requirement->projectLinks()->firstOrFail(),
            [
                'delivery_status' => ProjectDeliveryStatus::IN_TESTING,
            ],
        );

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
        $this->updateRequirementProjectForTest(
            $requirement->projectLinks()->firstOrFail(),
            [
                'delivery_status' => ProjectDeliveryStatus::DEPLOYED,
            ],
        );

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
        $this->assertNull($result->dev_lead_id);
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
        foreach ($links as $link) {
            $this->updateRequirementProjectForTest($link, [
                'delivery_status' => ProjectDeliveryStatus::DEPLOYED,
            ]);
        }
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
        $actor = $this->userWithPermission('it_pm', 'requirement.transition');
        $version = ProjectVersion::factory()->create([
            'status' => ProjectVersionStatus::IN_DEVELOPMENT,
        ]);
        DB::table('project_members')->insert([
            'project_id' => $version->project_id,
            'user_id' => $actor->id,
            'role_in_project' => 'pm',
            'assigned_at' => now(),
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
        $actor = $this->userWithPermission('it_pm', 'requirement.transition');
        $link = RequirementProject::factory()->create([
            'delivery_status' => ProjectDeliveryStatus::IN_DEVELOPMENT,
        ]);
        DB::table('project_members')->insert([
            'project_id' => $link->project_id,
            'user_id' => $actor->id,
            'role_in_project' => 'pm',
            'assigned_at' => now(),
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

    public function test_internal_user_cannot_transition_an_unrelated_target_project(): void
    {
        $actor = $this->userWithPermission('it_pm', 'requirement.transition');
        $visibleProject = Project::factory()->create();
        $forbiddenProject = Project::factory()->create();
        $targetVersion = ProjectVersion::factory()->for($forbiddenProject)->create();
        DB::table('project_members')->insert([
            'project_id' => $visibleProject->id,
            'user_id' => $actor->id,
            'role_in_project' => 'pm',
            'assigned_at' => now(),
        ]);
        $requirement = Requirement::factory()->create([
            'status' => RequirementStatus::ASSIGNED->value,
        ]);
        RequirementProject::factory()->for($requirement)->for($visibleProject)->create();
        $forbiddenLink = RequirementProject::factory()
            ->for($requirement)
            ->forVersion($targetVersion)
            ->create();

        $this->actingAs($actor)
            ->postJson("/api/requirements/{$requirement->id}/status", [
                'project_id' => $forbiddenProject->id,
                'status' => ProjectDeliveryStatus::IN_DEVELOPMENT->value,
            ])->assertForbidden()
            ->assertJsonPath('code', 403);

        $this->assertSame(
            ProjectDeliveryStatus::ASSIGNED,
            $forbiddenLink->refresh()->delivery_status,
        );
        $this->assertSame(
            RequirementStatus::ASSIGNED->value,
            $requirement->refresh()->status,
        );
        $this->assertDatabaseCount('project_version_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_pending_requirement_update_adds_project_and_records_one_complete_revision(): void
    {
        $requester = $this->userWithPermission('requester', 'requirement.edit');
        $firstProject = Project::factory()->create();
        $secondProject = Project::factory()->create();
        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'version' => 1,
            'title' => 'Original title',
        ]);
        RequirementProject::factory()->for($requirement)->for($firstProject)->create();

        $this->actingAs($requester)
            ->putJson("/api/requirements/{$requirement->id}", [
                'title' => 'Revised title',
                'project_ids' => [$firstProject->id, $secondProject->id],
            ])->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.dev_lead_id', null);

        $requirement->refresh();
        $this->assertSame(2, $requirement->version);
        $this->assertSame(
            [$firstProject->id, $secondProject->id],
            $requirement->projectLinks()->orderBy('project_id')->pluck('project_id')->all(),
        );
        $this->assertDatabaseCount('requirement_versions', 1);
        $snapshot = RequirementVersion::query()->sole();
        $this->assertSame(
            ['project_ids', 'title'],
            collect($snapshot->changes)->pluck('field')->sort()->values()->all(),
        );
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_update_rejects_an_empty_project_collection(): void
    {
        $requester = $this->userWithPermission('requester', 'requirement.edit');
        $project = Project::factory()->create();
        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
        ]);
        RequirementProject::factory()->for($requirement)->for($project)->create();

        $this->actingAs($requester)
            ->putJson("/api/requirements/{$requirement->id}", [
                'project_ids' => [],
            ])->assertUnprocessable()
            ->assertJsonPath('code', 422);

        $this->actingAs($requester)
            ->putJson("/api/requirements/{$requirement->id}", [
                'project_ids' => [$project->id, $project->id],
            ])->assertUnprocessable()
            ->assertJsonPath('code', 422);

        $this->assertSame([$project->id], $requirement->projectLinks()->pluck('project_id')->all());
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_approved_requirement_cannot_change_project_collection(): void
    {
        $requester = $this->userWithPermission('requester', 'requirement.edit');
        $firstProject = Project::factory()->create();
        $secondProject = Project::factory()->create();
        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::ASSIGNED->value,
            'version' => 3,
        ]);
        RequirementProject::factory()->for($requirement)->for($firstProject)->create([
            'delivery_status' => ProjectDeliveryStatus::ASSIGNED,
        ]);

        $this->actingAs($requester)
            ->putJson("/api/requirements/{$requirement->id}", [
                'project_ids' => [$secondProject->id],
            ])->assertConflict()
            ->assertJsonPath('code', 409)
            ->assertJsonPath('error_code', 'REQUIREMENT_PROJECT_SCOPE_LOCKED');

        $this->assertSame(3, $requirement->refresh()->version);
        $this->assertSame(RequirementStatus::ASSIGNED->value, $requirement->status);
        $this->assertSame([$firstProject->id], $requirement->projectLinks()->pluck('project_id')->all());
        $this->assertSame(
            ProjectDeliveryStatus::ASSIGNED,
            $requirement->projectLinks()->sole()->delivery_status,
        );
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_non_requester_cannot_resubmit_over_http(): void
    {
        $requester = User::factory()->systemUser()->create();
        $actor = $this->userWithPermission('it_pm', 'requirement.edit');
        $reviewer = User::factory()->internal()->create();
        $project = Project::factory()->create();
        DB::table('project_members')->insert([
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'role_in_project' => 'pm',
            'assigned_at' => now(),
        ]);
        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => $reviewer->id,
            'review_comment' => 'Rejected',
            'reviewed_at' => now(),
            'version' => 2,
        ]);
        RequirementProject::factory()->for($requirement)->for($project)->create();

        $this->actingAs($actor)
            ->postJson("/api/requirements/{$requirement->id}/resubmit", [
                'title' => 'Unauthorized revision',
            ])->assertForbidden()
            ->assertJsonPath('code', 403);

        $this->assertSame(2, $requirement->refresh()->version);
        $this->assertSame('Rejected', $requirement->review_comment);
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_review_is_rejected_over_http_without_audit(): void
    {
        $reviewer = $this->userWithPermission('it_pm', 'requirement.approve');
        $project = Project::factory()->create();
        DB::table('project_members')->insert([
            'project_id' => $project->id,
            'user_id' => $reviewer->id,
            'role_in_project' => 'pm',
            'assigned_at' => now(),
        ]);
        $requirement = Requirement::factory()->create([
            'status' => RequirementStatus::ASSIGNED->value,
            'reviewer_id' => $reviewer->id,
            'review_comment' => 'Approved',
            'reviewed_at' => now(),
        ]);
        RequirementProject::factory()->for($requirement)->for($project)->create();

        $this->actingAs($reviewer)
            ->postJson("/api/requirements/{$requirement->id}/review", [
                'action' => 'approve',
            ])->assertUnprocessable()
            ->assertJsonPath('code', 422);

        $this->assertSame(RequirementStatus::ASSIGNED->value, $requirement->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_no_change_update_does_not_increment_version_or_create_snapshot(): void
    {
        $requester = $this->userWithPermission('requester', 'requirement.edit');
        $project = Project::factory()->create();
        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'title' => 'Unchanged',
            'version' => 4,
            'expected_completion_date' => '2026-12-10',
        ]);
        RequirementProject::factory()->for($requirement)->for($project)->create();

        $this->actingAs($requester)
            ->putJson("/api/requirements/{$requirement->id}", [
                'title' => 'Unchanged',
                'expected_completion_date' => '2026-12-10',
            ])->assertOk()
            ->assertJsonPath('data.version', 4);

        $this->assertSame(4, $requirement->refresh()->version);
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_unique_revision_conflict_returns_stable_409_and_rolls_back_update(): void
    {
        $requester = $this->userWithPermission('requester', 'requirement.edit');
        $project = Project::factory()->create();
        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'title' => 'Before conflict',
            'version' => 1,
        ]);
        RequirementProject::factory()->for($requirement)->for($project)->create();
        RequirementVersion::query()->create([
            'requirement_id' => $requirement->id,
            'version_number' => 2,
            'changed_by_id' => $requester->id,
            'changed_at' => now(),
            'changes' => [],
            'change_summary' => 'Concurrent revision',
        ]);

        $this->actingAs($requester)
            ->putJson("/api/requirements/{$requirement->id}", [
                'title' => 'After conflict',
            ])->assertConflict()
            ->assertJsonPath('code', 409)
            ->assertJsonPath('error_code', 'REQUIREMENT_VERSION_CONFLICT');

        $requirement->refresh();
        $this->assertSame('Before conflict', $requirement->title);
        $this->assertSame(1, $requirement->version);
        $this->assertDatabaseCount('requirement_versions', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_approved_requirement_without_project_links_cannot_be_aggregated(): void
    {
        $requirement = Requirement::factory()->create([
            'status' => RequirementStatus::ASSIGNED->value,
        ]);

        try {
            $this->service()->recalculateAggregateStatus($requirement);
            $this->fail('Approved requirements without project links must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('project_ids', $exception->errors());
        }

        $this->assertSame(
            RequirementStatus::ASSIGNED->value,
            $requirement->refresh()->status,
        );
    }

    public function test_submit_rolls_back_when_audit_insert_fails(): void
    {
        $requester = $this->userWithPermission('requester', 'requirement.create');
        $project = Project::factory()->create();
        $this->failAuditWrites();

        $this->actingAs($requester)
            ->postJson('/api/requirements', [
                'title' => 'Rollback submission',
                'description' => 'Must not persist',
                'priority' => 2,
                'requirement_type' => 1,
                'project_ids' => [$project->id],
            ])->assertInternalServerError();

        $this->assertDatabaseCount('requirements', 0);
        $this->assertDatabaseCount('requirement_project', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_review_rolls_back_when_audit_insert_fails(): void
    {
        $reviewer = $this->userWithPermission('it_pm', 'requirement.approve');
        $project = Project::factory()->create();
        DB::table('project_members')->insert([
            'project_id' => $project->id,
            'user_id' => $reviewer->id,
            'role_in_project' => 'pm',
            'assigned_at' => now(),
        ]);
        $requirement = Requirement::factory()->create([
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => null,
            'reviewed_at' => null,
        ]);
        $link = RequirementProject::factory()->for($requirement)->for($project)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_DEVELOPMENT,
        ]);
        $this->failAuditWrites();

        $this->actingAs($reviewer)
            ->postJson("/api/requirements/{$requirement->id}/review", [
                'action' => 'approve',
            ])->assertInternalServerError();

        $requirement->refresh();
        $this->assertSame(RequirementStatus::PENDING_REVIEW->value, $requirement->status);
        $this->assertNull($requirement->reviewer_id);
        $this->assertNull($requirement->reviewed_at);
        $this->assertSame(
            ProjectDeliveryStatus::IN_DEVELOPMENT,
            $link->refresh()->delivery_status,
        );
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_resubmit_rolls_back_when_audit_insert_fails(): void
    {
        $requester = $this->userWithPermission('requester', 'requirement.edit');
        $reviewer = User::factory()->internal()->create();
        $project = Project::factory()->create();
        $newProject = Project::factory()->create();
        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => $reviewer->id,
            'review_comment' => 'Rejected',
            'reviewed_at' => now(),
            'title' => 'Rejected title',
            'version' => 2,
        ]);
        RequirementProject::factory()->for($requirement)->for($project)->create();
        $this->failAuditWrites();

        $this->actingAs($requester)
            ->postJson("/api/requirements/{$requirement->id}/resubmit", [
                'title' => 'Should roll back',
                'project_ids' => [$newProject->id],
            ])->assertInternalServerError();

        $requirement->refresh();
        $this->assertSame('Rejected title', $requirement->title);
        $this->assertSame(2, $requirement->version);
        $this->assertSame($reviewer->id, $requirement->reviewer_id);
        $this->assertSame('Rejected', $requirement->review_comment);
        $this->assertNull($requirement->dev_lead_id);
        $this->assertSame([$project->id], $requirement->projectLinks()->pluck('project_id')->all());
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_update_rolls_back_when_audit_insert_fails(): void
    {
        $requester = $this->userWithPermission('requester', 'requirement.edit');
        $project = Project::factory()->create();
        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'title' => 'Before audit failure',
            'version' => 5,
        ]);
        RequirementProject::factory()->for($requirement)->for($project)->create();
        $this->failAuditWrites();

        $this->actingAs($requester)
            ->putJson("/api/requirements/{$requirement->id}", [
                'title' => 'After audit failure',
            ])->assertInternalServerError();

        $requirement->refresh();
        $this->assertSame('Before audit failure', $requirement->title);
        $this->assertSame(5, $requirement->version);
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_transition_rolls_back_pivot_aggregate_and_history_when_audit_insert_fails(): void
    {
        $actor = $this->userWithPermission('it_pm', 'requirement.transition');
        $version = ProjectVersion::factory()->create();
        DB::table('project_members')->insert([
            'project_id' => $version->project_id,
            'user_id' => $actor->id,
            'role_in_project' => 'pm',
            'assigned_at' => now(),
        ]);
        $requirement = Requirement::factory()->create([
            'status' => RequirementStatus::ASSIGNED->value,
        ]);
        $link = RequirementProject::factory()
            ->for($requirement)
            ->forVersion($version)
            ->create(['delivery_status' => ProjectDeliveryStatus::ASSIGNED]);
        $this->failAuditWrites();

        $this->actingAs($actor)
            ->postJson("/api/requirements/{$requirement->id}/status", [
                'project_id' => $version->project_id,
                'status' => ProjectDeliveryStatus::IN_DEVELOPMENT->value,
            ])->assertInternalServerError();

        $this->assertSame(
            ProjectDeliveryStatus::ASSIGNED,
            $link->refresh()->delivery_status,
        );
        $this->assertSame(
            RequirementStatus::ASSIGNED->value,
            $requirement->refresh()->status,
        );
        $this->assertDatabaseCount('project_version_histories', 0);
        $this->assertDatabaseCount('requirement_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_update_result_reports_the_action_decided_under_lock(): void
    {
        $requester = User::factory()->systemUser()->create();
        $reviewer = User::factory()->internal()->create();
        $project = Project::factory()->create();
        $pending = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
        ]);
        RequirementProject::factory()->for($pending)->for($project)->create();
        $rejected = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => $reviewer->id,
            'review_comment' => 'Rejected',
            'reviewed_at' => now(),
        ]);
        RequirementProject::factory()->for($rejected)->for($project)->create();

        $updated = $this->service()->update($pending, $requester, [
            'title' => 'Ordinary update',
        ]);
        $resubmitted = $this->service()->update($rejected, $requester, [
            'title' => 'Requester resubmission',
        ]);

        $this->assertInstanceOf(RequirementUpdateResult::class, $updated);
        $this->assertSame('updated', $updated->action);
        $this->assertSame('Requirement updated.', $updated->message());
        $this->assertSame($pending->id, $updated->requirement->id);
        $this->assertInstanceOf(RequirementUpdateResult::class, $resubmitted);
        $this->assertSame('resubmitted', $resubmitted->action);
        $this->assertSame('Requirement resubmitted.', $resubmitted->message());
        $this->assertSame($rejected->id, $resubmitted->requirement->id);
    }

    public function test_put_response_message_uses_the_locked_workflow_action(): void
    {
        $requester = $this->userWithPermission('requester', 'requirement.edit');
        $reviewer = User::factory()->internal()->create();
        $project = Project::factory()->create();
        $pending = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
        ]);
        RequirementProject::factory()->for($pending)->for($project)->create();
        $rejected = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => $reviewer->id,
            'review_comment' => 'Rejected',
            'reviewed_at' => now(),
        ]);
        RequirementProject::factory()->for($rejected)->for($project)->create();

        $this->actingAs($requester)
            ->putJson("/api/requirements/{$pending->id}", [
                'title' => 'Ordinary HTTP update',
            ])->assertOk()
            ->assertJsonPath('message', 'Requirement updated.');

        $this->actingAs($requester)
            ->putJson("/api/requirements/{$rejected->id}", [
                'title' => 'HTTP resubmission',
            ])->assertOk()
            ->assertJsonPath('message', 'Requirement resubmitted.');
    }

    public function test_update_and_resubmit_lock_the_requirement_row_in_the_real_service(): void
    {
        $requester = User::factory()->systemUser()->create();
        $project = Project::factory()->create();
        $pending = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'title' => 'Before update lock',
        ]);
        RequirementProject::factory()->for($pending)->for($project)->create();
        $rejected = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => User::factory()->internal(),
            'review_comment' => 'Rejected',
            'reviewed_at' => now(),
            'title' => 'Before resubmit lock',
        ]);
        RequirementProject::factory()->for($rejected)->for($project)->create();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->service()->update($pending, $requester, [
            'title' => 'After update lock',
        ]);

        $this->assertTrue(
            collect($queries)->contains(
                static fn (string $sql): bool => str_contains(
                    $sql,
                    'from "requirements"',
                ) && str_contains($sql, 'for update'),
            ),
            "Requirement update did not acquire a row lock:\n".implode("\n", $queries),
        );

        $queries = [];
        $this->service()->resubmit($rejected, $requester, [
            'title' => 'After resubmit lock',
        ]);

        $this->assertTrue(
            collect($queries)->contains(
                static fn (string $sql): bool => str_contains(
                    $sql,
                    'from "requirements"',
                ) && str_contains($sql, 'for update'),
            ),
            "Requirement resubmit did not acquire a row lock:\n".implode("\n", $queries),
        );
    }

    public function test_requirement_row_lock_serializes_revisions_across_pgsql_connections(): void
    {
        $connectionName = 'pgsql_requirement_lock_test';
        config([
            "database.connections.{$connectionName}" => config('database.connections.pgsql'),
        ]);
        DB::purge($connectionName);
        $secondary = DB::connection($connectionName);

        $userId = $secondary->table('users')->insertGetId([
            'username' => 'lock-test-user',
            'password' => 'not-used',
            'first_name' => 'Lock',
            'last_name' => 'Test',
            'email' => 'lock-test@example.test',
            'is_active' => true,
            'is_staff' => false,
            'date_joined' => now(),
            'user_type' => 1,
            'must_change_password' => false,
            'is_disabled' => false,
            'display_name' => 'Lock Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $requirementId = $secondary->table('requirements')->insertGetId([
            'title' => 'Locked revision',
            'description' => 'Lock serialization proof',
            'priority' => 1,
            'requirement_type' => 1,
            'submitter_id' => $userId,
            'submitted_at' => now(),
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'created_by_id' => $userId,
        ]);

        try {
            $locked = DB::table('requirements')
                ->where('id', $requirementId)
                ->lockForUpdate()
                ->first();
            $this->assertNotNull($locked);

            $secondary->beginTransaction();
            $secondary->statement("SET LOCAL lock_timeout = '150ms'");

            try {
                $secondary->table('requirements')
                    ->where('id', $requirementId)
                    ->lockForUpdate()
                    ->first();
                $this->fail('The second connection must wait for the requirement row lock.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->getCode());
                $this->assertStringContainsString(
                    'lock timeout',
                    strtolower($exception->getMessage()),
                );
            }
        } finally {
            if ($secondary->transactionLevel() > 0) {
                $secondary->rollBack();
            }

            DB::connection()->rollBack();
            $secondary->transaction(function () use (
                $secondary,
                $requirementId,
                $userId,
            ): void {
                $linkIds = $secondary->table('requirement_project')
                    ->where('requirement_id', $requirementId)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();
                $secondary->select(
                    "SELECT set_config('itpms.requirement_project_write_ids', ?, true)",
                    [json_encode($linkIds, JSON_THROW_ON_ERROR)],
                );
                $secondary->table('requirements')->where('id', $requirementId)->delete();
                $secondary->table('users')->where('id', $userId)->delete();
            });
            DB::connection()->beginTransaction();
            DB::purge($connectionName);
        }
    }

    public function test_successful_http_workflow_actions_each_write_exactly_one_audit(): void
    {
        $requester = $this->userWithPermission('requester', 'requirement.create');
        $this->grantRolePermission('requester', 'requirement.edit');
        $project = Project::factory()->create();

        $created = $this->actingAs($requester)
            ->postJson('/api/requirements', [
                'title' => 'Audit lifecycle',
                'description' => 'Exactly one audit per operation',
                'priority' => 2,
                'requirement_type' => 1,
                'project_ids' => [$project->id],
            ])->assertCreated()
            ->json('data');
        $requirement = Requirement::query()->findOrFail($created['id']);

        $this->actingAs($requester)
            ->putJson("/api/requirements/{$requirement->id}", [
                'title' => 'Audit lifecycle revised',
            ])->assertOk();

        $reviewer = $this->userWithPermission('it_pm', 'requirement.approve');
        $this->grantRolePermission('it_pm', 'requirement.transition');
        DB::table('project_members')->insert([
            'project_id' => $project->id,
            'user_id' => $reviewer->id,
            'role_in_project' => 'pm',
            'assigned_at' => now(),
        ]);
        $this->actingAs($reviewer)
            ->postJson("/api/requirements/{$requirement->id}/review", [
                'action' => 'approve',
            ])->assertOk();

        $this->actingAs($reviewer)
            ->postJson("/api/requirements/{$requirement->id}/status", [
                'project_id' => $project->id,
                'status' => ProjectDeliveryStatus::IN_DEVELOPMENT->value,
            ])->assertOk();

        $rejected = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => $reviewer->id,
            'review_comment' => 'Rejected',
            'reviewed_at' => now(),
        ]);
        RequirementProject::factory()->for($rejected)->for($project)->create();
        $this->actingAs($requester)
            ->postJson("/api/requirements/{$rejected->id}/resubmit", [
                'title' => 'Audit resubmission',
            ])->assertOk();

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('target_id', (string) $requirement->id)
                ->where('action_type', 1)
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('target_id', (string) $requirement->id)
                ->where('action_type', 2)
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('target_id', (string) $requirement->id)
                ->where('action_type', 5)
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('target_id', (string) $requirement->id)
                ->where('action_type', 4)
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('target_id', (string) $rejected->id)
                ->where('action_type', 2)
                ->count(),
        );
        $this->assertDatabaseCount('audit_logs', 5);
    }

    public function test_external_delivery_transition_rejects_locked_versions_and_deployment_action(): void
    {
        $actor = $this->userWithPermission('it_pm', 'requirement.transition');
        $project = Project::factory()->create();
        DB::table('project_members')->insert([
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'role_in_project' => 'pm',
            'assigned_at' => now(),
        ]);

        foreach ([
            ProjectVersionStatus::READY_TO_RELEASE,
            ProjectVersionStatus::RELEASED,
            ProjectVersionStatus::ARCHIVED,
        ] as $status) {
            $version = ProjectVersion::factory()->for($project)->inTesting()->create();
            $link = RequirementProject::factory()->forVersion($version)->create([
                'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY,
            ]);
            DB::table('project_versions')->where('id', $version->id)->update([
                'status' => $status->value,
            ]);
            $version->refresh();

            try {
                $this->service()->transitionProjectDelivery(
                    $link->requirement,
                    $project->id,
                    ProjectDeliveryStatus::DEPLOYED,
                    $actor,
                );
                $this->fail('Expected locked version delivery conflict.');
            } catch (DomainConflictException $exception) {
                $this->assertSame('VERSION_LOCKED', $exception->errorCode);
                $this->assertSame(409, $exception->status);
                $this->assertSame([
                    'project_version_id' => [$version->id],
                    'status' => ['current' => $status->value],
                ], $exception->errors);
            }
            $this->assertSame(ProjectDeliveryStatus::PENDING_DEPLOY, $link->fresh()->delivery_status);
        }

        $testing = ProjectVersion::factory()->for($project)->inTesting()->create();
        $link = RequirementProject::factory()->forVersion($testing)->create([
            'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY,
        ]);
        try {
            $this->service()->transitionProjectDelivery(
                $link->requirement,
                $project->id,
                ProjectDeliveryStatus::DEPLOYED,
                $actor,
            );
            $this->fail('Expected release-only deployment conflict.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('RELEASE_ACTION_REQUIRED', $exception->errorCode);
            $this->assertSame(409, $exception->status);
            $this->assertSame([
                'delivery_status' => [
                    'current' => ProjectDeliveryStatus::PENDING_DEPLOY->value,
                    'requested' => ProjectDeliveryStatus::DEPLOYED->value,
                ],
            ], $exception->errors);
        }
        $this->assertSame(ProjectDeliveryStatus::PENDING_DEPLOY, $link->fresh()->delivery_status);
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

    private function failAuditWrites(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fail_audit_write()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'injected audit failure';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fail_audit_write
            BEFORE INSERT ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION fail_audit_write();
            SQL);
    }

    private function grantRolePermission(string $roleCode, string $permissionCode): void
    {
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
