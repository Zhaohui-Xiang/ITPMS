<?php

namespace Tests\Feature\Services;

use App\Enums\DefectStatus;
use App\Enums\ProjectVersionStatus;
use App\Exceptions\DomainConflictException;
use App\Models\Defect;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\User;
use App\Services\DefectWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DefectWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_action_predicates_match_the_lifecycle_matrix(): void
    {
        foreach (DefectStatus::cases() as $status) {
            $this->assertSame(
                $status === DefectStatus::PENDING_CONFIRM,
                $status->canConfirm(),
            );
            $this->assertSame(
                in_array($status, [DefectStatus::CONFIRMED, DefectStatus::REOPENED], true),
                $status->canAssign(),
            );
            $this->assertSame($status === DefectStatus::FIXING, $status->canResolve());
            $this->assertSame($status === DefectStatus::PENDING_RETEST, $status->canVerify());
            $this->assertSame($status === DefectStatus::CLOSED, $status->canReopen());
        }
    }

    public function test_complete_defect_lifecycle_applies_state_and_timestamp_rules(): void
    {
        [$project, $developer] = $this->supplierProjectAndDeveloper();
        $actor = User::factory()->internal()->create();
        $tester = User::factory()->supplier()->create();
        $defect = Defect::factory()->create([
            'project_id' => $project->id,
            'status' => DefectStatus::PENDING_CONFIRM->value,
        ]);

        $defect = $this->service()->confirm($defect, $actor);
        $this->assertSame(DefectStatus::CONFIRMED->value, $defect->status);

        $defect = $this->service()->assign($defect, $developer, $actor);
        $this->assertSame(DefectStatus::FIXING->value, $defect->status);
        $this->assertSame($developer->id, $defect->assignee_id);

        $defect = $this->service()->resolve(
            $defect,
            $developer,
            'Corrected the validation branch.',
        );
        $this->assertSame(DefectStatus::PENDING_RETEST->value, $defect->status);
        $this->assertSame('Corrected the validation branch.', $defect->fix_description);

        $defect = $this->service()->verify($defect, $tester, 'pass', 'Verified.');
        $this->assertSame(DefectStatus::CLOSED->value, $defect->status);
        $this->assertNotNull($defect->closed_at);

        $defect = $this->service()->reopen($defect, $actor, 'Regression reproduced.');
        $this->assertSame(DefectStatus::REOPENED->value, $defect->status);
        $this->assertNull($defect->closed_at);

        $defect = $this->service()->assign($defect, $developer, $actor);
        $this->assertSame(DefectStatus::FIXING->value, $defect->status);
        $this->assertDatabaseCount('audit_logs', 6);
    }

    public function test_failed_verification_reopens_the_defect(): void
    {
        $tester = User::factory()->supplier()->create();
        $defect = Defect::factory()->create([
            'status' => DefectStatus::PENDING_RETEST->value,
            'closed_at' => null,
        ]);

        $result = $this->service()->verify(
            $defect,
            $tester,
            'fail',
            'Still reproducible.',
        );

        $this->assertSame(DefectStatus::REOPENED->value, $result->status);
        $this->assertNull($result->closed_at);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[DataProvider('invalidActionProvider')]
    public function test_invalid_state_actions_return_stable_conflict(
        string $action,
        DefectStatus $status,
    ): void {
        [$project, $developer] = $this->supplierProjectAndDeveloper();
        $actor = User::factory()->internal()->create();
        $defect = Defect::factory()->create([
            'project_id' => $project->id,
            'status' => $status->value,
            'assignee_id' => $developer->id,
        ]);

        try {
            match ($action) {
                'confirm' => $this->service()->confirm($defect, $actor),
                'assign' => $this->service()->assign($defect, $developer, $actor),
                'resolve' => $this->service()->resolve($defect, $developer, 'Fixed'),
                'verify' => $this->service()->verify($defect, $actor, 'pass'),
                'reopen' => $this->service()->reopen($defect, $actor, 'Reproduced'),
            };
            $this->fail('The defect action should have been rejected.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('INVALID_DEFECT_TRANSITION', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        $this->assertSame($status->value, $defect->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * @return iterable<string, array{string, DefectStatus}>
     */
    public static function invalidActionProvider(): iterable
    {
        $allowed = [
            'confirm' => [DefectStatus::PENDING_CONFIRM],
            'assign' => [DefectStatus::CONFIRMED, DefectStatus::REOPENED],
            'resolve' => [DefectStatus::FIXING],
            'verify' => [DefectStatus::PENDING_RETEST],
            'reopen' => [DefectStatus::CLOSED],
        ];

        foreach ($allowed as $action => $statuses) {
            foreach (DefectStatus::cases() as $status) {
                if (! in_array($status, $statuses, true)) {
                    yield "{$action} from {$status->name}" => [$action, $status];
                }
            }
        }
    }

    public function test_resolve_rechecks_the_assignee(): void
    {
        [$project, $developer] = $this->supplierProjectAndDeveloper();
        $otherDeveloper = User::factory()->supplier()->withRole('supplier_dev')->create();
        $defect = Defect::factory()->create([
            'project_id' => $project->id,
            'status' => DefectStatus::FIXING->value,
            'assignee_id' => $developer->id,
        ]);

        try {
            $this->service()->resolve($defect, $otherDeveloper, 'Not my defect');
            $this->fail('Only the assigned developer may resolve the defect.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('DEFECT_ASSIGNEE_MISMATCH', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        $this->assertSame(DefectStatus::FIXING->value, $defect->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_reopen_requires_a_non_blank_reason(): void
    {
        $actor = User::factory()->internal()->create();
        $defect = Defect::factory()->create([
            'status' => DefectStatus::CLOSED->value,
            'closed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->service()->reopen($defect, $actor, ' ');
        } finally {
            $this->assertSame(DefectStatus::CLOSED->value, $defect->refresh()->status);
            $this->assertNotNull($defect->closed_at);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_create_validates_requirement_project_relation_and_audits(): void
    {
        $reporter = User::factory()->internal()->create();
        $requirement = Requirement::factory()->create();
        $project = Project::factory()->create();
        RequirementProject::factory()
            ->for($requirement)
            ->for($project)
            ->create();

        $defect = $this->service()->create([
            'requirement_id' => $requirement->id,
            'project_id' => $project->id,
            'title' => 'Lifecycle bypass',
            'description' => 'The endpoint accepts an invalid state.',
            'severity' => 1,
            'defect_type' => 2,
            'discovery_phase' => 2,
            'screenshot' => null,
        ], $reporter);

        $this->assertSame(DefectStatus::PENDING_CONFIRM->value, $defect->status);
        $this->assertSame($reporter->id, $defect->reporter_id);
        $this->assertSame($reporter->id, $defect->created_by_id);
        $this->assertNotNull($defect->discovered_at);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_system_requester_cannot_create_for_another_requesters_requirement(): void
    {
        $reporter = User::factory()->systemUser()->create();
        $owner = User::factory()->systemUser()->create();
        $requirement = Requirement::factory()->create([
            'submitter_id' => $owner->id,
            'created_by_id' => $owner->id,
        ]);
        $project = Project::factory()->create();
        RequirementProject::factory()
            ->for($requirement)
            ->for($project)
            ->create();

        $this->expectException(AuthorizationException::class);

        try {
            $this->service()->create([
                'requirement_id' => $requirement->id,
                'project_id' => $project->id,
                'title' => 'Cross requester defect',
                'description' => 'Must not affect another requester.',
                'severity' => 1,
                'defect_type' => 2,
                'discovery_phase' => 2,
            ], $reporter);
        } finally {
            $this->assertDatabaseCount('defects', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_create_rejects_a_project_outside_requirement_scope(): void
    {
        $reporter = User::factory()->internal()->create();
        $requirement = Requirement::factory()->create();
        $project = Project::factory()->create();

        $this->expectException(ValidationException::class);

        try {
            $this->service()->create([
                'requirement_id' => $requirement->id,
                'project_id' => $project->id,
                'title' => 'Invalid relation',
                'description' => 'Wrong project.',
                'severity' => 2,
                'defect_type' => 2,
                'discovery_phase' => 2,
            ], $reporter);
        } finally {
            $this->assertDatabaseCount('defects', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_defect_and_audit_roll_back_together(): void
    {
        $actor = User::factory()->internal()->create();
        $defect = Defect::factory()->create([
            'status' => DefectStatus::PENDING_CONFIRM->value,
        ]);
        $this->failAuditWrites();

        try {
            $this->service()->confirm($defect, $actor);
            $this->fail('The injected audit failure should abort the transaction.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('injected audit failure', $exception->getMessage());
        }

        $this->assertSame(DefectStatus::PENDING_CONFIRM->value, $defect->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_locked_project_version_rejects_defect_mutation(): void
    {
        $actor = User::factory()->internal()->create();
        $version = ProjectVersion::factory()->inTesting()->create();
        $link = RequirementProject::factory()->forVersion($version)->create();
        $defect = Defect::factory()->create([
            'requirement_id' => $link->requirement_id,
            'project_id' => $link->project_id,
            'status' => DefectStatus::PENDING_CONFIRM->value,
        ]);
        DB::table('project_versions')->where('id', $version->id)->update([
            'status' => ProjectVersionStatus::READY_TO_RELEASE->value,
        ]);

        try {
            $this->service()->confirm($defect, $actor);
            $this->fail('A locked project version must reject defect mutation.');
        } catch (QueryException $exception) {
            $this->assertSame('IV001', $exception->errorInfo[0]);
        }

        $this->assertSame(DefectStatus::PENDING_CONFIRM->value, $defect->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * @return array{Project, User}
     */
    private function supplierProjectAndDeveloper(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Supplier organization',
            'org_type' => 2,
            'is_active' => true,
        ]);
        $developer = User::factory()->supplier()->withRole('supplier_dev')->create();
        $developer->organizations()->attach($organization, [
            'role_in_org' => 'developer',
            'is_primary' => true,
            'assigned_at' => now(),
        ]);
        $project = Project::factory()->create([
            'supplier_org_id' => $organization->id,
        ]);

        return [$project, $developer];
    }

    private function service(): DefectWorkflowService
    {
        return app(DefectWorkflowService::class);
    }

    private function failAuditWrites(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fail_defect_audit_write()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'injected audit failure';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fail_defect_audit_write
            BEFORE INSERT ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION fail_defect_audit_write();
            SQL);
    }
}
