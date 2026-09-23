<?php

namespace Tests\Feature\Api;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use App\Enums\Priority;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\RequirementStatus;
use App\Enums\TaskStatus;
use App\Models\Defect;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class DashboardContractTest extends TestCase
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
        Cache::flush();
    }

    public function test_it_pm_summary_is_scoped_and_uses_the_exact_contract(): void
    {
        $fixture = $this->scopeFixture();
        $this->assertTrue($fixture['it_pm']->hasPermission('project_version.view'));

        $response = $this->actingAs($fixture['it_pm'])
            ->getJson('/api/dashboard/summary');

        $this->assertSummaryContract($response);
        $this->assertSame(
            [
                'pending_reviews',
                'due_tasks',
                'pending_defects',
                'unplanned_requirements',
                'blocked_releases',
            ],
            array_column($response->json('data.metrics'), 'key'),
        );
        $this->assertSame([
            'pending_reviews' => 2,
            'due_tasks' => 1,
            'pending_defects' => 2,
            'unplanned_requirements' => 2,
            'blocked_releases' => 1,
        ], collect($response->json('data.metrics'))->pluck('value', 'key')->all());
        $this->assertQueueContains($response, 'priority_queue', 'requirement', $fixture['own_requirement']->id);
        $this->assertQueueContains($response, 'release_risks', 'project_version', $fixture['inside_version']->id);
        $this->assertQueueExcludesIds($response, $fixture['outside_ids']);
    }

    public function test_supplier_developer_summary_is_limited_to_supplier_scope_and_assignments(): void
    {
        $fixture = $this->scopeFixture();

        $response = $this->actingAs($fixture['supplier_dev'])
            ->getJson('/api/dashboard/summary');

        $this->assertSummaryContract($response);
        $this->assertSame(
            ['due_tasks', 'pending_defects', 'blocked_releases'],
            array_column($response->json('data.metrics'), 'key'),
        );
        $this->assertSame([
            'due_tasks' => 1,
            'pending_defects' => 1,
            'blocked_releases' => 1,
        ], collect($response->json('data.metrics'))->pluck('value', 'key')->all());
        $this->assertQueueContains($response, 'priority_queue', 'task', $fixture['inside_task']->id);
        $this->assertQueueContains($response, 'priority_queue', 'defect', $fixture['inside_defect']->id);
        $this->assertQueueContains($response, 'release_risks', 'project_version', $fixture['inside_version']->id);
        $this->assertQueueExcludesIds($response, $fixture['outside_ids']);
    }

    public function test_requester_summary_never_exposes_other_requesters_records_in_a_shared_project(): void
    {
        $fixture = $this->scopeFixture();

        $response = $this->actingAs($fixture['requester'])
            ->getJson('/api/dashboard/summary');

        $this->assertSummaryContract($response);
        $this->assertSame(
            ['pending_reviews', 'pending_defects', 'unplanned_requirements', 'blocked_releases'],
            array_column($response->json('data.metrics'), 'key'),
        );
        // 待办口径=我可执行：刚提交待审核的需求不需要提交人动作，不计入
        $this->assertSame([
            'pending_reviews' => 0,
            'pending_defects' => 1,
            'unplanned_requirements' => 1,
            'blocked_releases' => 1,
        ], collect($response->json('data.metrics'))->pluck('value', 'key')->all());
        $this->assertQueueContains($response, 'priority_queue', 'defect', $fixture['own_defect']->id);
        $this->assertQueueContains($response, 'release_risks', 'project_version', $fixture['inside_version']->id);

        $visibleIds = collect($response->json('data.priority_queue'))
            ->map(fn (array $item): string => $item['type'].':'.$item['id'])
            ->all();
        $this->assertNotContains('requirement:'.$fixture['own_requirement']->id, $visibleIds);
        $this->assertNotContains('requirement:'.$fixture['other_inside_requirement']->id, $visibleIds);
        $this->assertNotContains('task:'.$fixture['inside_task']->id, $visibleIds);
        $this->assertNotContains('defect:'.$fixture['inside_defect']->id, $visibleIds);
        $this->assertQueueExcludesIds($response, $fixture['outside_ids']);
    }

    public function test_requester_pending_reviews_cover_rejected_requirements_awaiting_resubmission(): void
    {
        $fixture = $this->scopeFixture();
        // 被驳回待重送：提交人可执行（修改后重新送审），计入待办并进入优先队列
        $fixture['own_requirement']->update([
            'reviewer_id' => $fixture['it_pm']->id,
            'review_comment' => '请补充验收标准后重送',
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($fixture['requester'])
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $metrics = collect($response->json('data.metrics'))->pluck('value', 'key');
        $this->assertSame(1, $metrics['pending_reviews']);
        $this->assertSame(
            '待我重新提交',
            collect($response->json('data.metrics'))->firstWhere('key', 'pending_reviews')['label'],
        );
        $this->assertQueueContains(
            $response,
            'priority_queue',
            'requirement',
            $fixture['own_requirement']->id,
        );
    }

    public function test_reviewer_pending_reviews_exclude_rejected_requirements_awaiting_resubmission(): void
    {
        $fixture = $this->scopeFixture();
        // 驳回后待提交人重送的需求不再计入审核人待办（审核人当前无可执行动作）
        $fixture['own_requirement']->update([
            'reviewer_id' => $fixture['it_pm']->id,
            'review_comment' => '请补充验收标准后重送',
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($fixture['it_pm'])
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $metrics = collect($response->json('data.metrics'))->pluck('value', 'key');
        // 仅剩 other_inside_requirement 一条待审核
        $this->assertSame(1, $metrics['pending_reviews']);
        $this->assertQueueExcludesIds($response, [
            'requirement' => $fixture['own_requirement']->id,
        ]);
    }

    public function test_priority_and_release_queues_are_risk_ordered_and_limited_to_ten_real_records(): void
    {
        $itPm = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->create([
            'manager_id' => $itPm->id,
            'created_by_id' => $itPm->id,
            'status' => 1,
        ]);
        $requirements = collect();
        $versions = collect();

        foreach (range(1, 12) as $number) {
            $requirement = Requirement::factory()->create([
                'title' => sprintf('Queue requirement %02d', $number),
                'status' => RequirementStatus::PENDING_REVIEW->value,
                'priority' => Priority::URGENT->value,
                'expected_completion_date' => today()->addDays(12 - $number),
            ]);
            RequirementProject::factory()->for($requirement)->for($project)->create();
            $requirements->push($requirement);

            $version = ProjectVersion::factory()->create([
                'project_id' => $project->id,
                'owner_id' => $itPm->id,
                'created_by_id' => $itPm->id,
                'status' => ProjectVersionStatus::IN_TESTING->value,
                'code' => sprintf('QUEUE-%02d', $number),
                'name' => sprintf('Queue release %02d', $number),
                'planned_release_date' => today()->addDays(12 - $number),
                'release_notes' => null,
            ]);
            $versions->push($version);
        }

        $response = $this->actingAs($itPm)
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $this->assertCount(10, $response->json('data.priority_queue'));
        $this->assertCount(10, $response->json('data.release_risks'));
        $this->assertSame($requirements->last()->id, $response->json('data.priority_queue.0.id'));
        $this->assertSame($versions->last()->id, $response->json('data.release_risks.0.id'));
        $this->assertSame(
            $requirements->slice(2)->reverse()->pluck('id')->values()->all(),
            array_column($response->json('data.priority_queue'), 'id'),
        );
        $this->assertSame(
            $versions->slice(2)->reverse()->pluck('id')->values()->all(),
            array_column($response->json('data.release_risks'), 'id'),
        );
    }

    public function test_release_risks_require_the_project_version_view_permission(): void
    {
        $fixture = $this->scopeFixture();
        $user = $fixture['supplier_dev'];
        $roleId = $user->roles()->where('code', 'supplier_dev')->value('roles.id');
        $permissionId = DB::table('permissions')
            ->where('code', 'project_version.view')
            ->value('id');

        DB::table('permission_role')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->delete();
        cache()->forget("user_{$user->id}_perm_project_version.view");

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $this->assertSame([], $response->json('data.release_risks'));
        $this->assertSame(
            0,
            collect($response->json('data.metrics'))
                ->firstWhere('key', 'blocked_releases')['value'],
        );
        $this->assertStringNotContainsString(
            'Scoped release risk',
            $response->getContent(),
        );
    }

    public function test_release_gate_queries_are_bounded_for_many_candidate_versions(): void
    {
        $itPm = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->create([
            'manager_id' => $itPm->id,
            'created_by_id' => $itPm->id,
            'status' => 1,
        ]);

        foreach (range(1, 30) as $number) {
            $version = ProjectVersion::factory()->create([
                'project_id' => $project->id,
                'owner_id' => $itPm->id,
                'created_by_id' => $itPm->id,
                'status' => ProjectVersionStatus::IN_TESTING->value,
                'code' => sprintf('BOUNDED-%02d', $number),
                'planned_release_date' => today()->addDays($number),
                'release_notes' => null,
            ]);
            RequirementProject::factory()->forVersion($version)->create([
                'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY->value,
            ]);
            Task::factory()->forVersionScope($version)->create([
                'status' => TaskStatus::COMPLETED->value,
                'completed_at' => now(),
            ]);
            Defect::factory()->forVersionScope($version)->create([
                'severity' => DefectSeverity::SERIOUS->value,
                'status' => DefectStatus::CLOSED->value,
                'closed_at' => now(),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($itPm)

            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            25,
            $queryCount,
            "Dashboard summary executed {$queryCount} queries for 30 versions.",
        );
    }

    public function test_release_risk_candidates_cover_due_override_and_safe_future_branches(): void
    {
        $itPm = User::factory()->withRole('it_pm')->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $project = Project::factory()->create([
            'manager_id' => $itPm->id,
            'created_by_id' => $itPm->id,
            'status' => 1,
        ]);
        $dueDraft = ProjectVersion::factory()->for($project)->create([
            'code' => 'DUE-DRAFT',
            'planned_release_date' => today()->addDays(2),
        ]);
        $futureDraft = ProjectVersion::factory()->for($project)->create([
            'code' => 'FUTURE-DRAFT',
            'planned_release_date' => today()->addDays(60),
        ]);
        $safeFutureReady = ProjectVersion::factory()
            ->for($project)
            ->ready()
            ->withPassingScope()
            ->create([
                'code' => 'SAFE-FUTURE',
                'planned_release_date' => today()->addDays(60),
            ]);
        $blockedFutureReady = ProjectVersion::factory()
            ->for($project)
            ->ready()
            ->create([
                'code' => 'BLOCKED-FUTURE',
                'planned_release_date' => today()->addDays(60),
                'release_notes' => null,
            ]);
        $forced = ProjectVersion::factory()->for($project)->inTesting()->create([
            'code' => 'FORCED-RELEASE',
            'planned_release_date' => today()->addDays(60),
            'release_notes' => null,
        ]);

        $this->actingAs($superAdmin)
            ->postJson("/api/project-versions/{$forced->id}/release", [
                'lock_version' => 1,
                'force' => true,
                'force_reason' => 'Critical production correction',
                'release_notes' => 'Forced dashboard candidate',
            ])
            ->assertOk()
            ->assertJsonPath('data.release_snapshot.is_override', true);

        $response = $this->actingAs($itPm)
            ->getJson('/api/dashboard/summary')
            ->assertOk();
        $riskIds = collect($response->json('data.release_risks'))->pluck('id');

        $this->assertContains($dueDraft->id, $riskIds);
        $this->assertContains($forced->id, $riskIds);
        $this->assertContains($blockedFutureReady->id, $riskIds);
        $this->assertNotContains($futureDraft->id, $riskIds);
        $this->assertNotContains($safeFutureReady->id, $riskIds);
        $this->assertSame(
            1,
            collect($response->json('data.metrics'))->firstWhere('key', 'blocked_releases')['value'],
        );
    }

    public function test_supplier_tester_queue_only_contains_projects_they_can_verify(): void
    {
        $fixture = $this->scopeFixture();
        $tester = User::factory()->withRole('supplier_tester')->create();
        $supplier = $fixture['supplier_dev']->organizations()->firstOrFail();
        $project = $fixture['inside_defect']->project;
        $tester->organizations()->attach($supplier, [
            'role_in_org' => 'member',
            'is_primary' => true,
            'assigned_at' => now(),
        ]);
        $retestDefect = Defect::factory()->create([
            'requirement_id' => $fixture['inside_defect']->requirement_id,
            'project_id' => $project->id,
            'title' => 'Tester membership boundary',
            'reporter_id' => $fixture['it_pm']->id,
            'created_by_id' => $fixture['it_pm']->id,
            'severity' => DefectSeverity::SERIOUS->value,
            'status' => DefectStatus::PENDING_RETEST->value,
        ]);

        $outsideMembership = $this->actingAs($tester)
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $this->assertSame(
            0,
            collect($outsideMembership->json('data.metrics'))
                ->firstWhere('key', 'pending_defects')['value'],
        );
        $this->assertQueueExcludesIds($outsideMembership, [
            'defect' => $retestDefect->id,
        ]);

        DB::table('project_members')->insert([
            'project_id' => $project->id,
            'user_id' => $tester->id,
            'role_in_project' => 'member',
        ]);

        $insideMembership = $this->actingAs($tester)
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $this->assertSame(
            1,
            collect($insideMembership->json('data.metrics'))
                ->firstWhere('key', 'pending_defects')['value'],
        );
        $this->assertQueueContains(
            $insideMembership,
            'priority_queue',
            'defect',
            $retestDefect->id,
        );
    }

    public function test_supplier_tester_pending_defects_include_defects_they_reported(): void
    {
        $fixture = $this->scopeFixture();
        $tester = User::factory()->withRole('supplier_tester')->create();
        $supplier = $fixture['supplier_dev']->organizations()->firstOrFail();
        $tester->organizations()->attach($supplier, [
            'role_in_org' => 'member',
            'is_primary' => true,
            'assigned_at' => now(),
        ]);
        // tester 是提交人但不是项目成员 —— 与线上 BUG-2 的场景一致
        $reportedDefect = Defect::factory()->create([
            'requirement_id' => $fixture['inside_defect']->requirement_id,
            'project_id' => $fixture['inside_defect']->project_id,
            'title' => 'Reporter-only retest defect',
            'reporter_id' => $tester->id,
            'created_by_id' => $tester->id,
            'severity' => DefectSeverity::SERIOUS->value,
            'status' => DefectStatus::PENDING_RETEST->value,
        ]);

        $response = $this->actingAs($tester)
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $this->assertSame(
            1,
            collect($response->json('data.metrics'))
                ->firstWhere('key', 'pending_defects')['value'],
        );
        $this->assertQueueContains(
            $response,
            'priority_queue',
            'defect',
            $reportedDefect->id,
        );
    }

    public function test_dashboard_targets_only_use_routes_supported_by_current_pages(): void
    {
        $fixture = $this->scopeFixture();

        $response = $this->actingAs($fixture['it_pm'])
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $metricTargets = collect($response->json('data.metrics'))
            ->pluck('target_url', 'key')
            ->all();
        $this->assertSame([
            'pending_reviews' => '/requirements',
            'due_tasks' => '/tasks',
            'pending_defects' => '/defects',
            'unplanned_requirements' => '/requirements',
            'blocked_releases' => '/projects',
        ], $metricTargets);

        $priority = collect($response->json('data.priority_queue'));
        $this->assertSame(
            '/tasks',
            $priority->firstWhere('type', 'task')['target_url'],
        );
        $this->assertSame(
            '/defects',
            $priority->firstWhere('type', 'defect')['target_url'],
        );
        $this->assertSame(
            "/projects/{$fixture['inside_defect']->project_id}/versions",
            collect($response->json('data.release_risks'))
                ->firstWhere('type', 'project_version')['target_url'],
        );
    }

    private function assertSummaryContract(TestResponse $response): void
    {
        $response->assertOk();
        $payload = $response->json();

        $this->assertSame(['code', 'message', 'data'], array_keys($payload));
        $this->assertSame(
            ['metrics', 'priority_queue', 'release_risks'],
            array_keys($payload['data']),
        );

        foreach (array_merge($payload['data']['priority_queue'], $payload['data']['release_risks']) as $item) {
            $this->assertSame(
                ['type', 'id', 'title', 'project', 'due_at', 'severity', 'target_url'],
                array_keys($item),
            );
        }
    }

    private function assertQueueContains(
        TestResponse $response,
        string $queue,
        string $type,
        int $id,
    ): void {
        $keys = collect($response->json("data.{$queue}"))
            ->map(fn (array $item): string => $item['type'].':'.$item['id'])
            ->all();

        $this->assertContains("{$type}:{$id}", $keys);
    }

    /**
     * @param  array<string, int>  $outsideIds
     */
    private function assertQueueExcludesIds(TestResponse $response, array $outsideIds): void
    {
        $keys = collect(array_merge(
            $response->json('data.priority_queue'),
            $response->json('data.release_risks'),
        ))->map(fn (array $item): string => $item['type'].':'.$item['id'])->all();

        foreach ($outsideIds as $type => $id) {
            $this->assertNotContains("{$type}:{$id}", $keys);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeFixture(): array
    {
        $itPm = User::factory()->withRole('it_pm')->create();
        $supplierDev = User::factory()->withRole('supplier_dev')->create();
        $requester = User::factory()->withRole('requester')->create();
        $otherRequester = User::factory()->withRole('requester')->create();
        $outsidePm = User::factory()->withRole('it_pm')->create();

        $insideSupplier = Organization::query()->create([
            'name' => 'Dashboard supplier',
            'org_type' => 2,
            'is_active' => true,
        ]);
        $outsideSupplier = Organization::query()->create([
            'name' => 'Outside supplier',
            'org_type' => 2,
            'is_active' => true,
        ]);
        $supplierDev->organizations()->attach($insideSupplier, [
            'role_in_org' => 'member',
            'is_primary' => true,
            'assigned_at' => now(),
        ]);

        $insideProject = Project::factory()->create([
            'name' => 'Scoped dashboard project',
            'manager_id' => $itPm->id,
            'supplier_org_id' => $insideSupplier->id,
            'created_by_id' => $itPm->id,
            'status' => 1,
        ]);
        $outsideProject = Project::factory()->create([
            'name' => 'Outside dashboard project',
            'manager_id' => $outsidePm->id,
            'supplier_org_id' => $outsideSupplier->id,
            'created_by_id' => $outsidePm->id,
            'status' => 1,
        ]);

        $ownRequirement = Requirement::factory()->create([
            'title' => 'Requester scoped review',
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'priority' => Priority::URGENT->value,
            'expected_completion_date' => today()->addDays(2),
        ]);
        $otherInsideRequirement = Requirement::factory()->create([
            'title' => 'Other requester shared project record',
            'submitter_id' => $otherRequester->id,
            'created_by_id' => $otherRequester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'priority' => Priority::HIGH->value,
            'expected_completion_date' => today()->addDays(3),
        ]);
        $ownUnplannedRequirement = Requirement::factory()->create([
            'title' => 'Requester own unplanned delivery',
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::ASSIGNED->value,
            'priority' => Priority::MEDIUM->value,
        ]);
        $outsideRequirement = Requirement::factory()->create([
            'title' => 'Outside inaccessible requirement',
            'submitter_id' => $otherRequester->id,
            'created_by_id' => $otherRequester->id,
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'priority' => Priority::URGENT->value,
            'expected_completion_date' => today(),
        ]);

        $insideVersion = ProjectVersion::factory()->create([
            'project_id' => $insideProject->id,
            'owner_id' => $itPm->id,
            'created_by_id' => $itPm->id,
            'status' => ProjectVersionStatus::DRAFT->value,
            'code' => 'SCOPED-RISK',
            'name' => 'Scoped release risk',
            'planned_release_date' => today()->addDays(2),
            'release_notes' => null,
        ]);

        RequirementProject::factory()
            ->for($ownRequirement)
            ->for($insideProject)
            ->forVersion($insideVersion)
            ->create(['delivery_status' => ProjectDeliveryStatus::IN_TESTING->value]);
        RequirementProject::factory()->for($otherInsideRequirement)->for($insideProject)->create();
        RequirementProject::factory()->for($ownUnplannedRequirement)->for($insideProject)->create();
        RequirementProject::factory()->for($outsideRequirement)->for($outsideProject)->create();
        DB::table('project_versions')->where('id', $insideVersion->id)->update([
            'status' => ProjectVersionStatus::IN_TESTING->value,
        ]);

        $insideTask = Task::factory()->create([
            'requirement_id' => $otherInsideRequirement->id,
            'project_id' => $insideProject->id,
            'title' => 'Supplier assigned scoped task',
            'assignee_id' => $supplierDev->id,
            'status' => TaskStatus::IN_PROGRESS->value,
            'priority' => Priority::HIGH->value,
            'due_date' => today()->addDay(),
            'created_by_id' => $itPm->id,
        ]);
        $outsideTask = Task::factory()->create([
            'requirement_id' => $outsideRequirement->id,
            'project_id' => $outsideProject->id,
            'title' => 'Outside inaccessible task',
            'assignee_id' => $supplierDev->id,
            'status' => TaskStatus::IN_PROGRESS->value,
            'priority' => Priority::URGENT->value,
            'due_date' => today(),
            'created_by_id' => $outsidePm->id,
        ]);

        $ownDefect = Defect::factory()->create([
            'requirement_id' => $ownRequirement->id,
            'project_id' => $insideProject->id,
            'title' => 'Requester own open defect',
            'reporter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'severity' => DefectSeverity::SERIOUS->value,
            'status' => DefectStatus::PENDING_CONFIRM->value,
        ]);
        $insideDefect = Defect::factory()->create([
            'requirement_id' => $otherInsideRequirement->id,
            'project_id' => $insideProject->id,
            'title' => 'Supplier assigned scoped defect',
            'assignee_id' => $supplierDev->id,
            'reporter_id' => $itPm->id,
            'created_by_id' => $itPm->id,
            'severity' => DefectSeverity::FATAL->value,
            'status' => DefectStatus::FIXING->value,
        ]);
        $outsideDefect = Defect::factory()->create([
            'requirement_id' => $outsideRequirement->id,
            'project_id' => $outsideProject->id,
            'title' => 'Outside inaccessible defect',
            'assignee_id' => $supplierDev->id,
            'reporter_id' => $outsidePm->id,
            'created_by_id' => $outsidePm->id,
            'severity' => DefectSeverity::FATAL->value,
            'status' => DefectStatus::FIXING->value,
        ]);

        $outsideVersion = ProjectVersion::factory()->create([
            'project_id' => $outsideProject->id,
            'owner_id' => $outsidePm->id,
            'created_by_id' => $outsidePm->id,
            'status' => ProjectVersionStatus::IN_TESTING->value,
            'code' => 'OUTSIDE-RISK',
            'name' => 'Outside inaccessible release risk',
            'planned_release_date' => today(),
            'release_notes' => null,
        ]);

        return [
            'it_pm' => $itPm,
            'supplier_dev' => $supplierDev,
            'requester' => $requester,
            'own_requirement' => $ownRequirement,
            'other_inside_requirement' => $otherInsideRequirement,
            'inside_task' => $insideTask,
            'own_defect' => $ownDefect,
            'inside_defect' => $insideDefect,
            'inside_version' => $insideVersion->refresh(),
            'outside_ids' => [
                'requirement' => $outsideRequirement->id,
                'task' => $outsideTask->id,
                'defect' => $outsideDefect->id,
                'project_version' => $outsideVersion->id,
            ],
        ];
    }
}
