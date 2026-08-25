<?php

namespace Tests\Feature\Services;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\RequirementStatus;
use App\Enums\TaskStatus;
use App\Exceptions\DomainConflictException;
use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\ProjectVersionHistory;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectVersionService;
use App\Services\ReleaseGateService;
use App\ValueObjects\ReleaseGateResult;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use JsonSerializable;
use Tests\TestCase;

class ReleaseGateServiceTest extends TestCase
{
    use DatabaseTruncation;

    private const CHECK_CODES = [
        'version_metadata',
        'non_empty_scope',
        'reviewed_assigned_scope',
        'project_delivery',
        'tasks_completed',
        'severe_defects_closed',
        'release_notes_present',
        'acceptance_complete',
    ];

    public function test_result_is_immutable_serializable_and_derives_stable_blocking_subset(): void
    {
        $checks = [
            $this->check('first', false, true),
            $this->check('second', true, true),
            $this->check('third', false, false),
            $this->check('fourth', false, true),
        ];

        $result = new ReleaseGateResult($checks);

        $this->assertInstanceOf(JsonSerializable::class, $result);
        $this->assertSame(['first', 'fourth'], array_column($result->blocking, 'code'));
        $this->assertSame([
            'passed' => false,
            'checks' => $checks,
            'blocking' => [$checks[0], $checks[3]],
        ], $result->jsonSerialize());
        $this->assertTrue((new \ReflectionClass($result))->isReadOnly());
    }

    public function test_every_result_has_stable_order_and_complete_check_shape(): void
    {
        $version = ProjectVersion::factory()->create();

        foreach (ProjectVersionStatus::cases() as $target) {
            if ($target === ProjectVersionStatus::DRAFT) {
                continue;
            }

            $result = $this->service()->check($version, $target);
            $this->assertSame(self::CHECK_CODES, array_column($result->checks, 'code'));
            foreach ($result->checks as $check) {
                $this->assertSame(
                    ['code', 'label', 'passed', 'blocking', 'details'],
                    array_keys($check),
                );
                $this->assertNotSame('', $check['label']);
                $this->assertIsBool($check['passed']);
                $this->assertIsBool($check['blocking']);
                $this->assertIsArray($check['details']);
            }
            $this->assertSame(
                array_values(array_filter(
                    $result->checks,
                    fn (array $check): bool => $check['blocking'] && ! $check['passed'],
                )),
                $result->blocking,
            );
        }
    }

    public function test_planned_requires_owner_and_planned_release_date(): void
    {
        $version = ProjectVersion::factory()->create([
            'owner_id' => null,
            'planned_release_date' => null,
        ]);

        $failed = $this->service()->check($version, ProjectVersionStatus::PLANNED);
        $this->assertSame(['version_metadata'], array_column($failed->blocking, 'code'));
        $this->assertSame(
            ['owner_id', 'planned_release_date'],
            $this->byCode($failed, 'version_metadata')['details']['missing'],
        );

        $version->update([
            'owner_id' => User::factory()->internal()->create()->id,
            'planned_release_date' => '2026-09-30',
        ]);
        $this->assertTrue(
            $this->service()->check($version->fresh(), ProjectVersionStatus::PLANNED)->passed,
        );
    }

    public function test_in_development_requires_nonempty_approved_scope_and_execution_owner(): void
    {
        $version = ProjectVersion::factory()->create();

        $empty = $this->service()->check($version, ProjectVersionStatus::IN_DEVELOPMENT);
        $this->assertSame(['non_empty_scope'], array_column($empty->blocking, 'code'));

        $pending = Requirement::factory()->create([
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'dev_lead_id' => null,
        ]);
        $link = RequirementProject::factory()->for($pending)->forVersion($version)->create();

        $failed = $this->service()->check($version, ProjectVersionStatus::IN_DEVELOPMENT);
        $this->assertSame(['reviewed_assigned_scope'], array_column($failed->blocking, 'code'));
        $review = $this->byCode($failed, 'reviewed_assigned_scope');
        $this->assertSame([$link->id], $review['details']['unapproved_requirement_project_ids']);
        $this->assertSame(
            [$link->id],
            $review['details']['missing_execution_owner_requirement_project_ids'],
        );

        $pending->update([
            'status' => RequirementStatus::ASSIGNED->value,
            'dev_lead_id' => User::factory()->internal()->create()->id,
        ]);
        $this->assertTrue(
            $this->service()->check($version, ProjectVersionStatus::IN_DEVELOPMENT)->passed,
        );
    }

    public function test_in_testing_requires_each_project_delivery_at_least_in_testing(): void
    {
        $version = ProjectVersion::factory()->create();
        $blocked = RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_DEVELOPMENT->value,
        ]);
        foreach ([
            ProjectDeliveryStatus::IN_TESTING,
            ProjectDeliveryStatus::PENDING_DEPLOY,
            ProjectDeliveryStatus::DEPLOYED,
            ProjectDeliveryStatus::ACCEPTED,
        ] as $status) {
            RequirementProject::factory()->forVersion($version)->create([
                'delivery_status' => $status->value,
            ]);
        }

        $failed = $this->service()->check($version, ProjectVersionStatus::IN_TESTING);
        $this->assertSame(['project_delivery'], array_column($failed->blocking, 'code'));
        $delivery = $this->byCode($failed, 'project_delivery');
        $this->assertSame([$blocked->id], $delivery['details']['failing_requirement_project_ids']);
        $this->assertSame(
            ProjectDeliveryStatus::IN_TESTING->value,
            $delivery['details']['minimum_status'],
        );

        $blocked->update(['delivery_status' => ProjectDeliveryStatus::IN_TESTING->value]);
        $this->assertTrue(
            $this->service()->check($version, ProjectVersionStatus::IN_TESTING)->passed,
        );
    }

    public function test_ready_gate_reports_all_blockers_in_approved_order(): void
    {
        $version = ProjectVersion::factory()->inTesting()->create(['release_notes' => null]);
        RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_TESTING->value,
        ]);
        Task::factory()->forVersionScope($version)->create([
            'status' => TaskStatus::IN_PROGRESS->value,
        ]);
        Defect::factory()->forVersionScope($version)->create([
            'severity' => DefectSeverity::FATAL->value,
            'status' => DefectStatus::FIXING->value,
        ]);
        Defect::factory()->forVersionScope($version)->create([
            'severity' => DefectSeverity::SERIOUS->value,
            'status' => DefectStatus::REOPENED->value,
        ]);

        $result = $this->service()->check($version, ProjectVersionStatus::READY_TO_RELEASE);

        $this->assertFalse($result->passed);
        $this->assertSame([
            'project_delivery',
            'tasks_completed',
            'severe_defects_closed',
            'release_notes_present',
        ], array_column($result->blocking, 'code'));
        $this->assertSame(
            2,
            $this->byCode($result, 'severe_defects_closed')['details']['open_count'],
        );
    }

    public function test_ready_scope_isolated_by_version_links_and_matching_project(): void
    {
        $project = Project::factory()->create();
        $version = ProjectVersion::factory()->for($project)->create([
            'release_notes' => 'Ready notes',
        ]);
        $link = RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY->value,
        ]);
        Task::factory()->create([
            'requirement_id' => $link->requirement_id,
            'project_id' => $project->id,
            'status' => TaskStatus::COMPLETED->value,
        ]);
        Defect::factory()->create([
            'requirement_id' => $link->requirement_id,
            'project_id' => $project->id,
            'severity' => DefectSeverity::SERIOUS->value,
            'status' => DefectStatus::CLOSED->value,
        ]);

        $otherVersion = ProjectVersion::factory()->for($project)->create();
        $otherLink = RequirementProject::factory()->forVersion($otherVersion)->create();
        Task::factory()->create([
            'requirement_id' => $otherLink->requirement_id,
            'project_id' => $project->id,
            'status' => TaskStatus::TODO->value,
        ]);
        Defect::factory()->create([
            'requirement_id' => $otherLink->requirement_id,
            'project_id' => $project->id,
            'severity' => DefectSeverity::FATAL->value,
            'status' => DefectStatus::FIXING->value,
        ]);

        $foreignProject = Project::factory()->create();
        RequirementProject::factory()->for($link->requirement)->for($foreignProject)->create();
        Task::factory()->create([
            'requirement_id' => $link->requirement_id,
            'project_id' => $foreignProject->id,
            'status' => TaskStatus::TODO->value,
        ]);
        Defect::factory()->create([
            'requirement_id' => $link->requirement_id,
            'project_id' => $foreignProject->id,
            'severity' => DefectSeverity::SERIOUS->value,
            'status' => DefectStatus::FIXING->value,
        ]);

        $result = $this->service()->check($version, ProjectVersionStatus::READY_TO_RELEASE);
        $this->assertTrue($result->passed);
        $this->assertSame([], $result->blocking);
    }

    public function test_ready_allows_open_normal_and_minor_defects_but_not_fatal_or_serious(): void
    {
        $version = ProjectVersion::factory()->create(['release_notes' => 'Notes']);
        RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY->value,
        ]);
        foreach ([DefectSeverity::NORMAL, DefectSeverity::MINOR] as $severity) {
            Defect::factory()->forVersionScope($version)->create([
                'severity' => $severity->value,
                'status' => DefectStatus::FIXING->value,
            ]);
        }

        $this->assertTrue(
            $this->service()->check($version, ProjectVersionStatus::READY_TO_RELEASE)->passed,
        );

        foreach ([DefectSeverity::FATAL, DefectSeverity::SERIOUS] as $severity) {
            $defect = Defect::factory()->forVersionScope($version)->create([
                'severity' => $severity->value,
                'status' => DefectStatus::FIXING->value,
            ]);
            $result = $this->service()->check($version, ProjectVersionStatus::READY_TO_RELEASE);
            $this->assertSame(
                ['severe_defects_closed'],
                array_column($result->blocking, 'code'),
            );
            $defect->update(['status' => DefectStatus::CLOSED->value]);
        }
    }

    public function test_released_reruns_exact_ready_checks(): void
    {
        $version = ProjectVersion::factory()->create(['release_notes' => null]);
        RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_TESTING->value,
        ]);
        Task::factory()->forVersionScope($version)->create(['status' => TaskStatus::TODO->value]);
        Defect::factory()->forVersionScope($version)->create([
            'severity' => DefectSeverity::SERIOUS->value,
            'status' => DefectStatus::FIXING->value,
        ]);

        $ready = $this->service()->check($version, ProjectVersionStatus::READY_TO_RELEASE);
        $released = $this->service()->check($version, ProjectVersionStatus::RELEASED);

        $this->assertSame($ready->checks, $released->checks);
        $this->assertSame($ready->blocking, $released->blocking);
    }

    public function test_archived_requires_acceptance_and_all_scope_defects_closed(): void
    {
        $version = ProjectVersion::factory()->inTesting()->create();
        $link = RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::DEPLOYED->value,
        ]);
        $defect = Defect::factory()->forVersionScope($version)->create([
            'severity' => DefectSeverity::MINOR->value,
            'status' => DefectStatus::FIXING->value,
        ]);

        $failed = $this->service()->check($version, ProjectVersionStatus::ARCHIVED);
        $this->assertSame(
            ['severe_defects_closed', 'acceptance_complete'],
            array_column($failed->blocking, 'code'),
        );
        $this->assertSame(
            'all',
            $this->byCode($failed, 'severe_defects_closed')['details']['mode'],
        );
        $this->assertSame(
            [$link->id],
            $this->byCode($failed, 'acceptance_complete')['details']['failing_requirement_project_ids'],
        );

        $link->update(['delivery_status' => ProjectDeliveryStatus::ACCEPTED->value]);
        $defect->update(['status' => DefectStatus::CLOSED->value]);
        $this->assertTrue(
            $this->service()->check($version, ProjectVersionStatus::ARCHIVED)->passed,
        );
    }

    public function test_failed_forward_gate_has_exact_conflict_and_zero_writes_while_rollback_skips_gate(): void
    {
        $actor = User::factory()->internal()->create();
        $draft = ProjectVersion::factory()->create([
            'owner_id' => null,
            'planned_release_date' => null,
            'lock_version' => 4,
        ]);

        try {
            app(ProjectVersionService::class)->transition(
                $draft,
                ProjectVersionStatus::PLANNED,
                4,
                $actor,
            );
            $this->fail('Expected the planned release gate to fail.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('RELEASE_GATE_FAILED', $exception->errorCode);
            $this->assertSame(409, $exception->status);
            $this->assertSame(['version_metadata'], array_column($exception->errors, 'code'));
        }

        $this->assertSame(ProjectVersionStatus::DRAFT, $draft->fresh()->status);
        $this->assertSame(4, $draft->fresh()->lock_version);
        $this->assertDatabaseCount('project_version_histories', 0);

        $testing = ProjectVersion::factory()->inTesting()->create(['lock_version' => 2]);
        $rolledBack = app(ProjectVersionService::class)->transition(
            $testing,
            ProjectVersionStatus::IN_DEVELOPMENT,
            2,
            $actor,
            'Scope correction',
        );
        $this->assertSame(ProjectVersionStatus::IN_DEVELOPMENT, $rolledBack->status);
        $this->assertSame(3, $rolledBack->lock_version);
        $this->assertSame('status_rollback', ProjectVersionHistory::sole()->event_type);
    }

    public function test_archived_transition_is_gated_and_direct_release_keeps_release_action_conflict(): void
    {
        $actor = User::factory()->internal()->create();
        $released = ProjectVersion::factory()->create([
            'status' => ProjectVersionStatus::RELEASED->value,
            'lock_version' => 3,
        ]);
        RequirementProject::factory()->forVersion($released)->create([
            'delivery_status' => ProjectDeliveryStatus::DEPLOYED->value,
        ]);

        try {
            app(ProjectVersionService::class)->transition(
                $released,
                ProjectVersionStatus::ARCHIVED,
                3,
                $actor,
            );
            $this->fail('Expected archive gate failure.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('RELEASE_GATE_FAILED', $exception->errorCode);
            $this->assertSame(['acceptance_complete'], array_column($exception->errors, 'code'));
        }
        $this->assertSame(ProjectVersionStatus::RELEASED, $released->fresh()->status);
        $this->assertSame(3, $released->fresh()->lock_version);

        $ready = ProjectVersion::factory()->ready()->create(['lock_version' => 6]);
        try {
            app(ProjectVersionService::class)->transition(
                $ready,
                ProjectVersionStatus::RELEASED,
                6,
                $actor,
            );
            $this->fail('Expected direct release to be rejected.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('RELEASE_ACTION_REQUIRED', $exception->errorCode);
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateDatabaseTables();
        } finally {
            parent::tearDown();
        }
    }

    private function service(): ReleaseGateService
    {
        return app(ReleaseGateService::class);
    }

    private function byCode(ReleaseGateResult $result, string $code): array
    {
        return collect($result->checks)->firstWhere('code', $code);
    }

    private function check(string $code, bool $passed, bool $blocking): array
    {
        return [
            'code' => $code,
            'label' => ucfirst($code),
            'passed' => $passed,
            'blocking' => $blocking,
            'details' => [],
        ];
    }
}
