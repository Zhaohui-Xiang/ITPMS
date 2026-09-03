<?php

namespace Tests\Feature\Services;

use App\Enums\ProjectVersionStatus;
use App\Enums\RequirementStatus;
use App\Enums\TaskStatus;
use App\Exceptions\DomainConflictException;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskWorkflowService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('allowedTransitionProvider')]
    public function test_allowed_transition_matrix(
        TaskStatus $from,
        TaskStatus $to,
    ): void {
        $actor = User::factory()->internal()->create();
        $task = Task::factory()->create([
            'status' => $from->value,
            'suspend_reason' => $from === TaskStatus::SUSPENDED ? 'Waiting for input' : null,
        ]);

        $result = $to === TaskStatus::SUSPENDED
            ? $this->service()->hold($task, $actor, 'Blocked by dependency')
            : $this->service()->transition($task, $to, $actor);

        $this->assertSame($to->value, $result->status);
        $this->assertSame(
            $to === TaskStatus::COMPLETED,
            $result->completed_at !== null,
        );
        $this->assertSame(
            $to === TaskStatus::SUSPENDED ? 'Blocked by dependency' : null,
            $result->suspend_reason,
        );
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /**
     * @return iterable<string, array{TaskStatus, TaskStatus}>
     */
    public static function allowedTransitionProvider(): iterable
    {
        yield 'todo to in progress' => [TaskStatus::TODO, TaskStatus::IN_PROGRESS];
        yield 'todo to suspended' => [TaskStatus::TODO, TaskStatus::SUSPENDED];
        yield 'in progress to completed' => [TaskStatus::IN_PROGRESS, TaskStatus::COMPLETED];
        yield 'in progress to suspended' => [TaskStatus::IN_PROGRESS, TaskStatus::SUSPENDED];
        yield 'suspended to todo' => [TaskStatus::SUSPENDED, TaskStatus::TODO];
        yield 'suspended to in progress' => [TaskStatus::SUSPENDED, TaskStatus::IN_PROGRESS];
    }

    #[DataProvider('invalidTransitionProvider')]
    public function test_invalid_transition_matrix_returns_stable_conflict(
        TaskStatus $from,
        TaskStatus $to,
    ): void {
        $actor = User::factory()->internal()->create();
        $task = Task::factory()->create(['status' => $from->value]);

        try {
            if ($to === TaskStatus::SUSPENDED) {
                $this->service()->hold($task, $actor, 'Invalid hold');
            } else {
                $this->service()->transition($task, $to, $actor);
            }
            $this->fail('The task transition should have been rejected.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('INVALID_TASK_TRANSITION', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        $this->assertSame($from->value, $task->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * @return iterable<string, array{TaskStatus, TaskStatus}>
     */
    public static function invalidTransitionProvider(): iterable
    {
        $allowed = [
            TaskStatus::TODO->value => [TaskStatus::IN_PROGRESS, TaskStatus::SUSPENDED],
            TaskStatus::IN_PROGRESS->value => [TaskStatus::COMPLETED, TaskStatus::SUSPENDED],
            TaskStatus::SUSPENDED->value => [TaskStatus::TODO, TaskStatus::IN_PROGRESS],
            TaskStatus::COMPLETED->value => [],
        ];

        foreach (TaskStatus::cases() as $from) {
            foreach (TaskStatus::cases() as $to) {
                if (! in_array($to, $allowed[$from->value], true)) {
                    yield "{$from->name} to {$to->name}" => [$from, $to];
                }
            }
        }
    }

    public function test_claim_atomically_assigns_an_unassigned_todo_task(): void
    {
        $developer = User::factory()->supplier()->create();
        $task = Task::factory()->create([
            'status' => TaskStatus::TODO->value,
            'assignee_id' => null,
        ]);

        $result = $this->service()->claim($task, $developer);

        $this->assertSame($developer->id, $result->assignee_id);
        $this->assertSame(TaskStatus::IN_PROGRESS->value, $result->status);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[DataProvider('unclaimableTaskProvider')]
    public function test_claim_rechecks_state_and_assignee(
        TaskStatus $status,
        bool $assigned,
    ): void {
        $developer = User::factory()->supplier()->create();
        $task = Task::factory()->create([
            'status' => $status->value,
            'assignee_id' => $assigned ? User::factory()->supplier() : null,
        ]);

        try {
            $this->service()->claim($task, $developer);
            $this->fail('The task should not be claimable.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('TASK_NOT_CLAIMABLE', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * @return iterable<string, array{TaskStatus, bool}>
     */
    public static function unclaimableTaskProvider(): iterable
    {
        yield 'assigned todo' => [TaskStatus::TODO, true];
        yield 'unassigned in progress' => [TaskStatus::IN_PROGRESS, false];
        yield 'unassigned suspended' => [TaskStatus::SUSPENDED, false];
        yield 'unassigned completed' => [TaskStatus::COMPLETED, false];
    }

    public function test_update_assigns_only_an_eligible_project_member(): void
    {
        $actor = User::factory()->internal()->create();
        $eligible = User::factory()->internal()->create();
        $project = Project::factory()->create();
        DB::table('project_members')->insert([
            'project_id' => $project->id,
            'user_id' => $eligible->id,
            'role_in_project' => 'member',
            'assigned_by_id' => $actor->id,
            'assigned_at' => now(),
            'created_at' => now(),
        ]);
        $task = Task::factory()->for($project)->create([
            'assignee_id' => null,
        ]);

        $updated = $this->service()->update(
            $task,
            ['assignee_id' => $eligible->id],
            $actor,
        );

        $this->assertSame($eligible->id, $updated->assignee_id);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_update_rejects_an_assignee_outside_the_project(): void
    {
        $actor = User::factory()->internal()->create();
        $unrelated = User::factory()->internal()->create();
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create([
            'assignee_id' => null,
        ]);

        try {
            $this->service()->update(
                $task,
                ['assignee_id' => $unrelated->id],
                $actor,
            );
            $this->fail('An unrelated user must not be assigned to the task.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignee_id', $exception->errors());
        }

        $this->assertNull($task->refresh()->assignee_id);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_supplier_developer_transition_rechecks_assignee_under_lock(): void
    {
        $developer = User::factory()->withRole('supplier_dev')->create();
        $replacement = User::factory()->supplier()->create();
        $task = Task::factory()->create([
            'status' => TaskStatus::IN_PROGRESS->value,
            'assignee_id' => $developer->id,
        ]);

        Task::query()
            ->whereKey($task->id)
            ->update(['assignee_id' => $replacement->id]);

        try {
            $this->service()->transition(
                $task,
                TaskStatus::COMPLETED,
                $developer,
            );
            $this->fail('A replaced developer must not transition the task.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('TASK_ASSIGNEE_MISMATCH', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        $task->refresh();
        $this->assertSame(TaskStatus::IN_PROGRESS->value, $task->status);
        $this->assertSame($replacement->id, $task->assignee_id);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_hold_requires_a_non_blank_reason(): void
    {
        $actor = User::factory()->internal()->create();
        $task = Task::factory()->create(['status' => TaskStatus::IN_PROGRESS->value]);

        $this->expectException(ValidationException::class);

        try {
            $this->service()->hold($task, $actor, '   ');
        } finally {
            $this->assertSame(TaskStatus::IN_PROGRESS->value, $task->refresh()->status);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_create_validates_requirement_project_relation_and_audits(): void
    {
        $actor = User::factory()->internal()->create();
        $requirement = Requirement::factory()->create();
        $project = Project::factory()->create();
        RequirementProject::factory()
            ->for($requirement)
            ->for($project)
            ->create();

        $task = $this->service()->create([
            'requirement_id' => $requirement->id,
            'project_id' => $project->id,
            'title' => 'Implement approval checks',
            'description' => 'Keep the workflow transactional.',
            'assignee_id' => null,
            'priority' => 2,
            'due_date' => '2026-10-01',
            'remind_days_before' => 2,
            'estimated_hours' => 8,
        ], $actor);

        $this->assertSame(TaskStatus::TODO->value, $task->status);
        $this->assertSame($actor->id, $task->created_by_id);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_create_rejects_an_unapproved_requirement(): void
    {
        $actor = User::factory()->internal()->create();
        $requirement = Requirement::factory()->create([
            'status' => RequirementStatus::PENDING_REVIEW->value,
        ]);
        $project = Project::factory()->create();
        RequirementProject::factory()
            ->for($requirement)
            ->for($project)
            ->create();

        try {
            $this->service()->create([
                'requirement_id' => $requirement->id,
                'project_id' => $project->id,
                'title' => 'Premature implementation task',
                'priority' => 2,
                'due_date' => '2026-10-01',
            ], $actor);
            $this->fail('An unapproved requirement must not receive tasks.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('REQUIREMENT_NOT_APPROVED', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_create_rejects_a_project_outside_requirement_scope(): void
    {
        $actor = User::factory()->internal()->create();
        $requirement = Requirement::factory()->create();
        $project = Project::factory()->create();

        $this->expectException(ValidationException::class);

        try {
            $this->service()->create([
                'requirement_id' => $requirement->id,
                'project_id' => $project->id,
                'title' => 'Invalid relation',
                'priority' => 2,
                'due_date' => '2026-10-01',
            ], $actor);
        } finally {
            $this->assertDatabaseCount('tasks', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_task_and_audit_roll_back_together(): void
    {
        $actor = User::factory()->internal()->create();
        $task = Task::factory()->create(['status' => TaskStatus::IN_PROGRESS->value]);
        $this->failAuditWrites();

        try {
            $this->service()->transition($task, TaskStatus::COMPLETED, $actor);
            $this->fail('The injected audit failure should abort the transaction.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('injected audit failure', $exception->getMessage());
        }

        $this->assertSame(TaskStatus::IN_PROGRESS->value, $task->refresh()->status);
        $this->assertNull($task->completed_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_locked_project_version_rejects_task_mutation(): void
    {
        $actor = User::factory()->internal()->create();
        $version = ProjectVersion::factory()->inTesting()->create();
        $link = RequirementProject::factory()->forVersion($version)->create();
        $task = Task::factory()->create([
            'requirement_id' => $link->requirement_id,
            'project_id' => $link->project_id,
            'status' => TaskStatus::IN_PROGRESS->value,
        ]);
        DB::table('project_versions')->where('id', $version->id)->update([
            'status' => ProjectVersionStatus::READY_TO_RELEASE->value,
        ]);

        try {
            $this->service()->transition($task, TaskStatus::COMPLETED, $actor);
            $this->fail('A locked project version must reject task mutation.');
        } catch (QueryException $exception) {
            $this->assertSame('IV001', $exception->errorInfo[0]);
        }

        $this->assertSame(TaskStatus::IN_PROGRESS->value, $task->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function service(): TaskWorkflowService
    {
        return app(TaskWorkflowService::class);
    }

    private function failAuditWrites(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fail_task_audit_write()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'injected audit failure';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fail_task_audit_write
            BEFORE INSERT ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION fail_task_audit_write();
            SQL);
    }
}
