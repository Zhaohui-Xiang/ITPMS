<?php

namespace Tests\Feature\Api;

use App\Enums\ProjectVersionStatus;
use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\ProjectVersionHistory;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProjectVersionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_manager_can_create_list_and_read_project_version(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();

        $response = $this->actingAs($manager)
            ->postJson("/api/projects/{$project->id}/versions", [
                'code' => '2026.08',
                'name' => 'August release',
                'planned_release_date' => '2026-08-24',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', ProjectVersionStatus::DRAFT->value)
            ->assertJsonPath('data.status_code', ProjectVersionStatus::DRAFT->name)
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonStructure([
                'data' => [
                    'project',
                    'owner',
                    'counts',
                    'gate_result',
                    'scope',
                    'history',
                    'release_snapshot',
                    'allowed_actions',
                ],
            ]);

        $versionId = $response->json('data.id');

        $this->actingAs($manager)
            ->getJson("/api/projects/{$project->id}/versions?page=1&page_size=10")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $versionId);

        $this->actingAs($manager)
            ->getJson("/api/project-versions/{$versionId}")
            ->assertOk()
            ->assertJsonPath('data.code', '2026.08');
    }

    public function test_gate_failure_uses_machine_error_contract(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()
            ->for($project)
            ->inTesting()
            ->create(['release_notes' => null]);

        $this->actingAs($manager)
            ->getJson("/api/project-versions/{$version->id}/gate-check")
            ->assertOk()
            ->assertJsonPath('data.passed', false);

        $this->actingAs($manager)
            ->postJson("/api/project-versions/{$version->id}/status", [
                'status' => ProjectVersionStatus::READY_TO_RELEASE->value,
                'lock_version' => 1,
            ])
            ->assertConflict()
            ->assertJsonPath('error_code', 'RELEASE_GATE_FAILED')
            ->assertJsonStructure(['errors']);
    }

    public function test_manager_can_plan_and_unplan_one_project_requirement(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()->for($project)->create();
        $link = RequirementProject::factory()->for($project)->create();

        $this->actingAs($manager)
            ->getJson("/api/requirements?project_id={$project->id}&version_scope=unplanned")
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->actingAs($manager)
            ->putJson(
                "/api/requirements/{$link->requirement_id}/projects/{$project->id}/version",
                [
                    'project_version_id' => $version->id,
                    'lock_version' => 1,
                ],
            )
            ->assertOk()
            ->assertJsonPath('data.project_version_id', $version->id)
            ->assertJsonPath('data.lock_version', 2);

        $this->actingAs($manager)
            ->deleteJson(
                "/api/requirements/{$link->requirement_id}/projects/{$project->id}/version",
                ['lock_version' => 2],
            )
            ->assertOk()
            ->assertJsonPath('data.project_version_id', null)
            ->assertJsonPath('data.lock_version', 3);
    }

    public function test_validation_and_duplicate_code_keep_machine_contracts(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();

        $this->actingAs($manager)
            ->postJson("/api/projects/{$project->id}/versions", [
                'code' => 'invalid code!',
                'name' => 'Invalid release',
                'planned_start_date' => '2026-09-10',
                'planned_release_date' => '2026-09-01',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['code', 'planned_release_date']]);

        ProjectVersion::factory()->for($project)->create(['code' => '2026.09']);

        $this->actingAs($manager)
            ->postJson("/api/projects/{$project->id}/versions", [
                'code' => '2026.09',
                'name' => 'Duplicate release',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VERSION_CODE_EXISTS')
            ->assertJsonStructure(['errors' => ['code']]);
    }

    public function test_manager_can_update_transition_delete_and_read_history(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();
        $editable = ProjectVersion::factory()->for($project)->create();

        $this->actingAs($manager)
            ->putJson("/api/project-versions/{$editable->id}", [
                'name' => 'Updated release',
                'lock_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated release')
            ->assertJsonPath('data.lock_version', 2);

        $this->actingAs($manager)
            ->deleteJson("/api/project-versions/{$editable->id}", [
                'lock_version' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('project_versions', ['id' => $editable->id]);

        $transitioning = ProjectVersion::factory()->for($project)->create([
            'owner_id' => $manager->id,
            'planned_release_date' => '2026-10-01',
        ]);

        $this->actingAs($manager)
            ->postJson("/api/project-versions/{$transitioning->id}/status", [
                'status' => ProjectVersionStatus::PLANNED->value,
                'lock_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectVersionStatus::PLANNED->value)
            ->assertJsonPath('data.lock_version', 2);

        $this->actingAs($manager)
            ->getJson("/api/project-versions/{$transitioning->id}/history")
            ->assertOk()
            ->assertJsonPath('data.items.0.event_type', 'status_forward')
            ->assertJsonPath('data.items.0.to_status', ProjectVersionStatus::PLANNED->value);
    }

    public function test_force_release_uses_service_reason_contract_and_returns_snapshot(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()
            ->for($project)
            ->inTesting()
            ->create(['release_notes' => null]);

        $this->actingAs($superAdmin)
            ->postJson("/api/project-versions/{$version->id}/release", [
                'lock_version' => 1,
                'force' => true,
                'force_reason' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'FORCE_REASON_REQUIRED')
            ->assertJsonStructure(['errors' => ['reason']]);

        $this->actingAs($superAdmin)
            ->postJson("/api/project-versions/{$version->id}/release", [
                'lock_version' => 1,
                'force' => true,
                'force_reason' => 'Emergency production correction',
                'release_notes' => 'Override release',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectVersionStatus::RELEASED->value)
            ->assertJsonPath('data.lock_version', 2)
            ->assertJsonPath('data.release_snapshot.is_override', true)
            ->assertJsonPath(
                'data.release_snapshot.override_reason',
                'Emergency production correction',
            );

        $this->actingAs($superAdmin)
            ->getJson("/api/project-versions/{$version->id}/history")
            ->assertOk()
            ->assertJsonPath('data.items.0.event_type', 'force_release');
    }

    public function test_assignment_rejects_a_version_from_another_project(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();
        $otherProject = Project::factory()->create();
        $otherVersion = ProjectVersion::factory()->for($otherProject)->create();
        $link = RequirementProject::factory()->for($project)->create();

        $this->actingAs($manager)
            ->putJson(
                "/api/requirements/{$link->requirement_id}/projects/{$project->id}/version",
                [
                    'project_version_id' => $otherVersion->id,
                    'lock_version' => 1,
                ],
            )
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['project_version_id']]);
    }

    public function test_unplanned_scope_requires_project_and_other_manager_is_forbidden(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $otherManager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()->for($project)->create();

        $this->actingAs($manager)
            ->getJson('/api/requirements?version_scope=unplanned')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['project_id']]);

        $this->actingAs($otherManager)
            ->getJson("/api/project-versions/{$version->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($otherManager)
            ->putJson("/api/project-versions/{$version->id}", [
                'name' => 'Forbidden update',
                'lock_version' => 1,
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_write_validation_preserves_json_types_and_effective_date_order(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $project = Project::factory()->withManager($manager)->create();
        $releaseVersion = ProjectVersion::factory()
            ->for($project)
            ->inTesting()
            ->create();

        $this->actingAs($superAdmin)
            ->postJson("/api/project-versions/{$releaseVersion->id}/release", [
                'lock_version' => '1',
                'force' => '1',
                'force_reason' => 'Invalid JSON scalar types',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['lock_version', 'force']]);

        $datedVersion = ProjectVersion::factory()->for($project)->create([
            'planned_start_date' => '2026-10-10',
            'planned_release_date' => '2026-10-20',
        ]);

        $this->actingAs($manager)
            ->putJson("/api/project-versions/{$datedVersion->id}", [
                'planned_release_date' => '2026-10-01',
                'lock_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['planned_release_date']]);
    }

    public function test_manager_can_normally_release_a_passing_ready_version(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()
            ->for($project)
            ->ready()
            ->withPassingScope()
            ->create(['owner_id' => $manager->id]);

        $this->actingAs($manager)
            ->getJson("/api/project-versions/{$version->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['transition', 'release']);

        $this->actingAs($superAdmin)
            ->getJson("/api/project-versions/{$version->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['force_release']);

        $this->actingAs($manager)
            ->postJson("/api/project-versions/{$version->id}/release", [
                'lock_version' => 1,
                'force' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectVersionStatus::RELEASED->value)
            ->assertJsonPath('data.lock_version', 2)
            ->assertJsonPath('data.release_snapshot.is_override', false);
    }

    public function test_policy_authorization_precedes_write_validation(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $otherManager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()->for($project)->create();
        $link = RequirementProject::factory()->for($project)->create();

        $this->actingAs($otherManager)
            ->postJson("/api/projects/{$project->id}/versions", [])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($otherManager)
            ->putJson("/api/project-versions/{$version->id}", [])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($otherManager)
            ->postJson("/api/project-versions/{$version->id}/status", [])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($otherManager)
            ->postJson("/api/project-versions/{$version->id}/release", [])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($otherManager)
            ->putJson(
                "/api/requirements/{$link->requirement_id}/projects/{$project->id}/version",
                [],
            )
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_unplanned_filter_does_not_distinguish_missing_and_unauthorized_project_ids(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $otherManager = User::factory()->withRole('it_pm')->create();
        $authorizedProject = Project::factory()->withManager($manager)->create();
        $unauthorizedProject = Project::factory()->withManager($otherManager)->create();
        $missingProjectId = $unauthorizedProject->id + 1000;

        RequirementProject::factory()->for($authorizedProject)->create();

        $unauthorizedResponse = $this->actingAs($manager)
            ->getJson(
                "/api/requirements?project_id={$unauthorizedProject->id}&version_scope=unplanned",
            );
        $missingResponse = $this->actingAs($manager)
            ->getJson(
                "/api/requirements?project_id={$missingProjectId}&version_scope=unplanned",
            );

        $this->assertSame(
            $unauthorizedResponse->getStatusCode(),
            $missingResponse->getStatusCode(),
        );
        $unauthorizedResponse->assertNotFound();
        $missingResponse->assertNotFound();
    }

    public function test_unplanned_filter_only_returns_the_authorized_target_project_relationship(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $otherManager = User::factory()->withRole('it_pm')->create();
        $authorizedProject = Project::factory()->withManager($manager)->create();
        $otherProject = Project::factory()->withManager($otherManager)->create();
        $authorizedLink = RequirementProject::factory()
            ->for($authorizedProject)
            ->create();

        RequirementProject::factory()
            ->for($authorizedLink->requirement)
            ->for($otherProject)
            ->create();

        $this->actingAs($manager)
            ->getJson(
                "/api/requirements?project_id={$authorizedProject->id}&version_scope=unplanned",
            )
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $authorizedLink->requirement_id)
            ->assertJsonCount(1, 'data.items.0.projects')
            ->assertJsonPath('data.items.0.projects.0.id', $authorizedProject->id);

        $this->actingAs($manager)
            ->getJson(
                "/api/requirements?project_id={$authorizedProject->id}",
            )
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonCount(2, 'data.items.0.projects');
    }

    public function test_non_release_writes_require_strict_scalars_dates_and_project_owner(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $otherManager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()->for($project)->create();
        $link = RequirementProject::factory()->for($project)->create();

        $this->actingAs($manager)
            ->postJson("/api/projects/{$project->id}/versions", [
                'code' => '2026.10',
                'name' => 'Strict fields',
                'owner_id' => (string) $manager->id,
                'planned_start_date' => '10/01/2026',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['owner_id', 'planned_start_date']]);

        $this->actingAs($manager)
            ->postJson("/api/projects/{$project->id}/versions", [
                'code' => '2026.11',
                'name' => 'Wrong owner',
                'owner_id' => $otherManager->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['owner_id']]);

        $this->actingAs($manager)
            ->putJson("/api/project-versions/{$version->id}", [
                'name' => 'String lock',
                'lock_version' => '1',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['lock_version']]);
        $this->actingAs($manager)
            ->putJson("/api/project-versions/{$version->id}", [
                'owner_id' => $otherManager->id,
                'lock_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['owner_id']]);

        $this->actingAs($manager)
            ->postJson("/api/project-versions/{$version->id}/status", [
                'status' => '2',
                'lock_version' => '1',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['status', 'lock_version']]);

        $this->actingAs($manager)
            ->putJson(
                "/api/requirements/{$link->requirement_id}/projects/{$project->id}/version",
                [
                    'project_version_id' => (string) $version->id,
                    'lock_version' => '1',
                ],
            )
            ->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => ['project_version_id', 'lock_version'],
            ]);
    }

    public function test_version_list_has_bounded_queries_and_history_is_paginated(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();
        $versions = ProjectVersion::factory()
            ->count(6)
            ->for($project)
            ->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($manager)
            ->getJson("/api/projects/{$project->id}/versions?page=1&page_size=5")
            ->assertOk()
            ->assertJsonPath('data.total', 6)
            ->assertJsonMissingPath('data.items.0.scope')
            ->assertJsonMissingPath('data.items.0.history')
            ->assertJsonMissingPath('data.items.0.release_snapshot')
            ->assertJsonMissingPath('data.items.0.gate_result');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            25,
            $queryCount,
            "Version list executed {$queryCount} database queries.",
        );

        $historyVersion = $versions->first();
        foreach (range(1, 3) as $sequence) {
            ProjectVersionHistory::query()->create([
                'project_version_id' => $historyVersion->id,
                'event_type' => 'status_forward',
                'from_status' => ProjectVersionStatus::DRAFT,
                'to_status' => ProjectVersionStatus::PLANNED,
                'actor_id' => $manager->id,
                'metadata' => ['sequence' => $sequence],
                'created_at' => now()->addSeconds($sequence),
            ]);
        }

        $this->actingAs($manager)
            ->getJson(
                "/api/project-versions/{$historyVersion->id}/history?page=1&page_size=2",
            )
            ->assertOk()
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 2)
            ->assertJsonPath('data.total', 3)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_detail_history_is_capped_newest_first_while_history_endpoint_reports_full_total(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()->for($project)->create();
        $createdAt = now();

        foreach (range(1, 7) as $sequence) {
            ProjectVersionHistory::query()->create([
                'project_version_id' => $version->id,
                'event_type' => 'status_forward',
                'from_status' => ProjectVersionStatus::DRAFT,
                'to_status' => ProjectVersionStatus::PLANNED,
                'actor_id' => $manager->id,
                'metadata' => ['sequence' => $sequence],
                'created_at' => $createdAt->copy()->addSeconds($sequence),
            ]);
        }

        $detailResponse = $this->actingAs($manager)
            ->getJson("/api/project-versions/{$version->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.histories', 7)
            ->assertJsonCount(5, 'data.history');

        $this->assertSame(
            [7, 6, 5, 4, 3],
            collect($detailResponse->json('data.history'))
                ->pluck('metadata.sequence')
                ->all(),
        );

        $historyResponse = $this->actingAs($manager)
            ->getJson(
                "/api/project-versions/{$version->id}/history?page=1&page_size=3",
            )
            ->assertOk()
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 3)
            ->assertJsonPath('data.total', 7)
            ->assertJsonCount(3, 'data.items');

        $this->assertSame(
            [7, 6, 5],
            collect($historyResponse->json('data.items'))
                ->pluck('metadata.sequence')
                ->all(),
        );
    }

    public function test_requester_only_sees_versions_and_counts_for_own_requirements(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $requester = User::factory()->withRole('requester')->create();
        $otherRequester = User::factory()->withRole('requester')->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $project = Project::factory()->withManager($manager)->create();
        $sharedVersion = ProjectVersion::factory()
            ->for($project)
            ->inTesting()
            ->create(['code' => 'OWN-SCOPE']);
        $hiddenVersion = ProjectVersion::factory()->for($project)->create([
            'code' => 'OTHER-SCOPE',
        ]);
        $ownRequirement = Requirement::factory()->create([
            'title' => 'Requester visible requirement',
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
        ]);
        $otherSharedRequirement = Requirement::factory()->create([
            'title' => 'Confidential shared version requirement',
            'submitter_id' => $otherRequester->id,
            'created_by_id' => $otherRequester->id,
        ]);
        $otherHiddenRequirement = Requirement::factory()->create([
            'title' => 'Confidential hidden version requirement',
            'submitter_id' => $otherRequester->id,
            'created_by_id' => $otherRequester->id,
        ]);

        RequirementProject::factory()
            ->for($ownRequirement)
            ->forVersion($sharedVersion)
            ->create();
        RequirementProject::factory()
            ->for($otherSharedRequirement)
            ->forVersion($sharedVersion)
            ->create();
        RequirementProject::factory()
            ->for($otherHiddenRequirement)
            ->forVersion($hiddenVersion)
            ->create();
        Task::factory()->create([
            'requirement_id' => $ownRequirement->id,
            'project_id' => $project->id,
        ]);
        Task::factory()->create([
            'requirement_id' => $otherSharedRequirement->id,
            'project_id' => $project->id,
        ]);
        Defect::factory()->create([
            'requirement_id' => $ownRequirement->id,
            'project_id' => $project->id,
        ]);
        Defect::factory()->create([
            'requirement_id' => $otherSharedRequirement->id,
            'project_id' => $project->id,
        ]);

        $this->actingAs($superAdmin)
            ->postJson("/api/project-versions/{$sharedVersion->id}/release", [
                'lock_version' => 1,
                'force' => true,
                'force_reason' => 'Requester projection fixture',
            ])
            ->assertOk();

        $this->actingAs($manager)
            ->getJson("/api/project-versions/{$sharedVersion->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.histories', 1)
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.release_snapshot.is_override', true)
            ->assertJsonPath(
                'data.release_snapshot.override_reason',
                'Requester projection fixture',
            );

        $this->actingAs($requester)
            ->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.requirements', 1)
            ->assertJsonPath('data.counts.tasks', 1)
            ->assertJsonPath('data.counts.defects', 1)
            ->assertJsonPath('data.counts.versions', 1);

        $this->actingAs($requester)
            ->getJson("/api/projects/{$project->id}/versions")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $sharedVersion->id)
            ->assertJsonPath('data.items.0.counts.requirements', 1)
            ->assertJsonPath('data.items.0.counts.tasks', 1)
            ->assertJsonPath('data.items.0.counts.defects', 1)
            ->assertJsonPath('data.items.0.counts.histories', 0);

        $this->actingAs($requester)
            ->getJson("/api/project-versions/{$hiddenVersion->id}")
            ->assertForbidden();

        $detail = $this->actingAs($requester)
            ->getJson("/api/project-versions/{$sharedVersion->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.scope')
            ->assertJsonPath('data.scope.0.requirement_id', $ownRequirement->id)
            ->assertJsonPath('data.gate_result', null)
            ->assertJsonPath('data.release_snapshot', null)
            ->assertJsonCount(0, 'data.history');
        $detail->assertJsonMissing([
            'title' => 'Confidential shared version requirement',
        ]);

        $this->actingAs($requester)
            ->getJson("/api/project-versions/{$sharedVersion->id}/gate-check")
            ->assertForbidden();
        $this->actingAs($requester)
            ->getJson("/api/project-versions/{$sharedVersion->id}/history")
            ->assertForbidden();
    }

    public function test_release_version_routes_reject_non_numeric_identifiers(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();

        $this->actingAs($manager)
            ->getJson('/api/projects/not-a-number/versions')
            ->assertNotFound();

        $this->actingAs($manager)
            ->getJson('/api/project-versions/not-a-number')
            ->assertNotFound();

        $this->actingAs($manager)
            ->putJson(
                "/api/requirements/not-a-number/projects/{$project->id}/version",
                [],
            )
            ->assertNotFound();

        $this->actingAs($manager)
            ->deleteJson(
                '/api/requirements/1/projects/not-a-number/version',
            )
            ->assertNotFound();
    }
}
