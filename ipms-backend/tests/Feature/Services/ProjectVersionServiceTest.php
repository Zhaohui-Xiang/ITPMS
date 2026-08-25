<?php

namespace Tests\Feature\Services;

use App\Enums\ProjectVersionStatus;
use App\Exceptions\DomainConflictException;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\ProjectVersionHistory;
use App\Models\RequirementProject;
use App\Models\User;
use App\Services\ProjectVersionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProjectVersionServiceTest extends TestCase
{
    use DatabaseTruncation;

    public function test_create_sets_defaults_and_code_is_unique_only_inside_project(): void
    {
        $actor = User::factory()->internal()->create();
        $owner = User::factory()->internal()->create();
        $firstProject = Project::factory()->create();
        $secondProject = Project::factory()->create();
        $data = [
            'code' => 'REL-2026-01',
            'name' => 'August release',
            'description' => 'Initial scope',
            'release_notes' => 'Release notes',
            'owner_id' => $owner->id,
            'planned_start_date' => '2026-08-20',
            'planned_release_date' => '2026-09-15',
        ];

        $first = $this->service()->create($firstProject, $data, $actor);
        $second = $this->service()->create($secondProject, $data, $actor);

        $this->assertSame($firstProject->id, $first->project_id);
        $this->assertSame($actor->id, $first->created_by_id);
        $this->assertSame($owner->id, $first->owner_id);
        $this->assertSame('August release', $first->name);
        $this->assertSame('Initial scope', $first->description);
        $this->assertSame('Release notes', $first->release_notes);
        $this->assertSame('2026-08-20', $first->planned_start_date->toDateString());
        $this->assertSame('2026-09-15', $first->planned_release_date->toDateString());
        $this->assertTrue($first->owner->is($owner));
        $this->assertTrue($first->creator->is($actor));
        $this->assertSame(ProjectVersionStatus::DRAFT, $first->status);
        $this->assertSame(1, $first->lock_version);
        $this->assertSame('REL-2026-01', $second->code);

        $count = ProjectVersion::count();
        $this->conflict(
            fn () => $this->service()->create($firstProject, $data, $actor),
            'VERSION_CODE_EXISTS',
            422,
            ['code' => ['The version code is already in use for this project.']],
        );
        $this->assertSame($count, ProjectVersion::count());
    }

    public function test_update_requeries_persisted_lock_and_increments_exactly_once(): void
    {
        $actor = User::factory()->internal()->create();
        $version = ProjectVersion::factory()->create([
            'name' => 'Before',
            'lock_version' => 2,
        ]);

        $updated = $this->service()->update(
            $version,
            ['name' => 'After', 'description' => 'Changed'],
            2,
            $actor,
        );
        $this->assertSame('After', $updated->name);
        $this->assertSame('Changed', $updated->description);
        $this->assertSame(3, $updated->lock_version);

        DB::table('project_versions')->where('id', $version->id)->update([
            'lock_version' => 5,
        ]);
        $this->conflict(
            fn () => $this->service()->update($version, ['name' => 'Stale'], 3, $actor),
            'STALE_VERSION',
            409,
            ['lock_version' => ['current' => 5]],
        );
        $this->assertSame('After', $version->fresh()->name);
        $this->assertSame(5, $version->fresh()->lock_version);
    }

    public function test_failed_update_rolls_back_fields_and_lock_and_immutable_statuses_reject(): void
    {
        $actor = User::factory()->internal()->create();
        $project = Project::factory()->create();
        ProjectVersion::factory()->for($project)->create(['code' => 'USED']);
        $version = ProjectVersion::factory()->for($project)->create([
            'code' => 'OPEN',
            'name' => 'Original',
            'lock_version' => 4,
        ]);

        $this->conflict(
            fn () => $this->service()->update(
                $version,
                ['code' => 'USED', 'name' => 'Changed'],
                4,
                $actor,
            ),
            'VERSION_CODE_EXISTS',
            422,
            ['code' => ['The version code is already in use for this project.']],
        );
        $version->refresh();
        $this->assertSame('OPEN', $version->code);
        $this->assertSame('Original', $version->name);
        $this->assertSame(4, $version->lock_version);

        foreach ([
            ProjectVersionStatus::READY_TO_RELEASE,
            ProjectVersionStatus::RELEASED,
            ProjectVersionStatus::ARCHIVED,
        ] as $status) {
            $locked = ProjectVersion::factory()->create([
                'status' => $status,
                'name' => 'Original',
            ]);
            $this->conflict(
                fn () => $this->service()->update($locked, ['name' => 'Changed'], 1, $actor),
                'VERSION_LOCKED',
                409,
                ['status' => ['current' => $status->value]],
            );
            $this->assertSame('Original', $locked->fresh()->name);
            $this->assertSame(1, $locked->fresh()->lock_version);
        }
    }

    public function test_transition_forward_and_rollback_write_exactly_one_history_each(): void
    {
        $actor = User::factory()->internal()->create();
        $forward = ProjectVersion::factory()->create([
            'lock_version' => 5,
            'planned_release_date' => '2026-09-30',
        ]);
        $updated = $this->service()->transition(
            $forward,
            ProjectVersionStatus::PLANNED,
            5,
            $actor,
        );
        $this->assertSame(ProjectVersionStatus::PLANNED, $updated->status);
        $this->assertSame(6, $updated->lock_version);
        $history = ProjectVersionHistory::sole();
        $this->assertSame('status_forward', $history->event_type);
        $this->assertSame(ProjectVersionStatus::DRAFT, $history->from_status);
        $this->assertSame(ProjectVersionStatus::PLANNED, $history->to_status);
        $this->assertSame($actor->id, $history->actor_id);
        $this->assertNull($history->reason);
        $this->assertSame([], $history->metadata);

        $rollback = ProjectVersion::factory()->inTesting()->create(['lock_version' => 2]);
        $this->conflict(
            fn () => $this->service()->transition(
                $rollback,
                ProjectVersionStatus::IN_DEVELOPMENT,
                2,
                $actor,
                ' ',
            ),
            'FORCE_REASON_REQUIRED',
            422,
            ['reason' => ['A reason is required.']],
        );
        $rolledBack = $this->service()->transition(
            $rollback,
            ProjectVersionStatus::IN_DEVELOPMENT,
            2,
            $actor,
            'Scope changed',
        );
        $this->assertSame(3, $rolledBack->lock_version);
        $rollbackHistory = ProjectVersionHistory::where('project_version_id', $rollback->id)->sole();
        $this->assertSame('status_rollback', $rollbackHistory->event_type);
        $this->assertSame('Scope changed', $rollbackHistory->reason);
        $this->assertDatabaseCount('project_version_histories', 2);
    }

    public function test_invalid_and_direct_released_transitions_have_exact_conflicts_and_no_writes(): void
    {
        $actor = User::factory()->internal()->create();
        $draft = ProjectVersion::factory()->create();
        $this->conflict(
            fn () => $this->service()->transition(
                $draft,
                ProjectVersionStatus::IN_DEVELOPMENT,
                1,
                $actor,
            ),
            'INVALID_VERSION_TRANSITION',
            409,
            ['status' => ['current' => 1, 'requested' => 3]],
        );

        $ready = ProjectVersion::factory()->ready()->create(['lock_version' => 7]);
        $this->conflict(
            fn () => $this->service()->transition(
                $ready,
                ProjectVersionStatus::RELEASED,
                7,
                $actor,
            ),
            'RELEASE_ACTION_REQUIRED',
            409,
            ['status' => ['requested' => 6]],
        );
        $this->assertSame(ProjectVersionStatus::DRAFT, $draft->fresh()->status);
        $this->assertSame(ProjectVersionStatus::READY_TO_RELEASE, $ready->fresh()->status);
        $this->assertSame(7, $ready->fresh()->lock_version);
        $this->assertDatabaseCount('project_version_histories', 0);
    }

    public function test_assignment_enforces_project_and_sets_single_target_with_history(): void
    {
        $actor = User::factory()->internal()->create();
        $version = ProjectVersion::factory()->create(['lock_version' => 3]);
        $foreign = RequirementProject::factory()->create();
        $this->conflict(
            fn () => $this->service()->assignRequirement($version, $foreign, 3, $actor),
            'VERSION_PROJECT_MISMATCH',
            409,
            ['project_id' => [
                'version' => $version->project_id,
                'requirement' => $foreign->project_id,
            ]],
        );

        $link = RequirementProject::factory()->create([
            'project_id' => $version->project_id,
            'project_version_id' => null,
        ]);
        $updated = $this->service()->assignRequirement($version, $link, 3, $actor);
        $link->refresh();
        $this->assertSame($version->id, $link->project_version_id);
        $this->assertSame($actor->id, $link->version_assigned_by_id);
        $this->assertNotNull($link->version_assigned_at);
        $this->assertSame(4, $updated->lock_version);
        $history = ProjectVersionHistory::sole();
        $this->assertSame('requirement_added', $history->event_type);
        $this->assertEquals([
            'requirement_project_id' => $link->id,
            'requirement_id' => $link->requirement_id,
            'old_version_id' => null,
            'new_version_id' => $version->id,
        ], $history->metadata);
    }

    public function test_moving_requirement_records_old_and_new_versions_with_consistent_scope(): void
    {
        $actor = User::factory()->internal()->create();
        $project = Project::factory()->create();
        $old = ProjectVersion::factory()->for($project)->create(['lock_version' => 8]);
        $new = ProjectVersion::factory()->for($project)->create(['lock_version' => 4]);
        $link = RequirementProject::factory()->forVersion($old)->create();

        $updated = $this->service()->assignRequirement($new, $link, 4, $actor, 'Move');

        $this->assertSame($new->id, $link->fresh()->project_version_id);
        $this->assertSame(5, $updated->lock_version);
        $this->assertSame(9, $old->fresh()->lock_version);
        $history = ProjectVersionHistory::sole();
        $this->assertSame($new->id, $history->project_version_id);
        $this->assertSame('requirement_moved', $history->event_type);
        $this->assertSame('Move', $history->reason);
        $this->assertSame($old->id, $history->metadata['old_version_id']);
        $this->assertSame($new->id, $history->metadata['new_version_id']);
    }

    public function test_reassigning_to_the_same_version_is_idempotent(): void
    {
        $originalActor = User::factory()->internal()->create();
        $newActor = User::factory()->internal()->create();
        $version = ProjectVersion::factory()->create(['lock_version' => 4]);
        $assignedAt = now()->subDay()->startOfSecond();
        $link = RequirementProject::factory()->forVersion($version)->create([
            'version_assigned_by_id' => $originalActor->id,
            'version_assigned_at' => $assignedAt,
        ]);

        $result = $this->service()->assignRequirement($version, $link, 4, $newActor);

        $this->assertSame(4, $result->lock_version);
        $this->assertSame($version->id, $link->fresh()->project_version_id);
        $this->assertSame($originalActor->id, $link->fresh()->version_assigned_by_id);
        $this->assertTrue($assignedAt->equalTo($link->fresh()->version_assigned_at));
        $this->assertDatabaseCount('project_version_histories', 0);
    }

    public function test_move_validation_failure_preserves_both_locks_and_pivot(): void
    {
        $actor = User::factory()->internal()->create();
        $project = Project::factory()->create();
        $old = ProjectVersion::factory()->for($project)->inTesting()->create(['lock_version' => 2]);
        $target = ProjectVersion::factory()->for($project)->create(['lock_version' => 7]);
        $link = RequirementProject::factory()->forVersion($old)->create();

        $this->conflict(
            fn () => $this->service()->assignRequirement($target, $link, 7, $actor),
            'FORCE_REASON_REQUIRED',
            422,
            ['reason' => ['A reason is required.']],
        );

        $this->assertSame(2, $old->fresh()->lock_version);
        $this->assertSame(7, $target->fresh()->lock_version);
        $this->assertSame($old->id, $link->fresh()->project_version_id);
        $this->assertDatabaseCount('project_version_histories', 0);
    }

    public function test_move_pivot_failure_rolls_back_both_locks_and_history(): void
    {
        $actor = User::factory()->internal()->make();
        $actor->setAttribute('id', (int) User::max('id') + 10_000);
        $project = Project::factory()->create();
        $old = ProjectVersion::factory()->for($project)->create(['lock_version' => 3]);
        $target = ProjectVersion::factory()->for($project)->create(['lock_version' => 5]);
        $link = RequirementProject::factory()->forVersion($old)->create();

        try {
            $this->service()->assignRequirement($target, $link, 5, $actor, 'Move');
            $this->fail('Expected the assignment actor foreign key to reject the pivot write.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }

        $this->assertSame(3, $old->fresh()->lock_version);
        $this->assertSame(5, $target->fresh()->lock_version);
        $this->assertSame($old->id, $link->fresh()->project_version_id);
        $this->assertDatabaseCount('project_version_histories', 0);
    }

    public function test_move_history_failure_rolls_back_pivot_and_both_locks(): void
    {
        $actor = User::factory()->internal()->create();
        $project = Project::factory()->create();
        $old = ProjectVersion::factory()->for($project)->create(['lock_version' => 6]);
        $target = ProjectVersion::factory()->for($project)->create(['lock_version' => 9]);
        $link = RequirementProject::factory()->forVersion($old)->create();

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION ipms_test_reject_version_history()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'forced project version history failure';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER ipms_test_reject_version_history
BEFORE INSERT ON project_version_histories
FOR EACH ROW EXECUTE FUNCTION ipms_test_reject_version_history();
SQL);

        try {
            $this->service()->assignRequirement($target, $link, 9, $actor, 'Move');
            $this->fail('Expected the history trigger to reject the move.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->getCode());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS ipms_test_reject_version_history ON project_version_histories');
            DB::unprepared('DROP FUNCTION IF EXISTS ipms_test_reject_version_history()');
        }

        $this->assertSame(6, $old->fresh()->lock_version);
        $this->assertSame(9, $target->fresh()->lock_version);
        $this->assertSame($old->id, $link->fresh()->project_version_id);
        $this->assertDatabaseCount('project_version_histories', 0);
    }

    public function test_scope_changes_enforce_status_locks_and_in_testing_reason(): void
    {
        $actor = User::factory()->internal()->create();
        foreach ([5, 6, 7] as $status) {
            $version = ProjectVersion::factory()->create(['status' => $status]);
            $link = RequirementProject::factory()->create(['project_id' => $version->project_id]);
            $this->conflict(
                fn () => $this->service()->assignRequirement($version, $link, 1, $actor),
                'VERSION_LOCKED',
                409,
                ['status' => ['current' => $status]],
            );
            $this->assertNull($link->fresh()->project_version_id);
        }

        $testing = ProjectVersion::factory()->inTesting()->create(['lock_version' => 2]);
        $link = RequirementProject::factory()->create(['project_id' => $testing->project_id]);
        $this->conflict(
            fn () => $this->service()->assignRequirement($testing, $link, 2, $actor),
            'FORCE_REASON_REQUIRED',
            422,
            ['reason' => ['A reason is required.']],
        );
        $assigned = $this->service()->assignRequirement(
            $testing,
            $link,
            2,
            $actor,
            'Late scope',
        );
        $this->assertSame(3, $assigned->lock_version);
    }

    public function test_unassign_clears_only_requested_link_and_records_history(): void
    {
        $actor = User::factory()->internal()->create();
        $version = ProjectVersion::factory()->create(['lock_version' => 6]);
        $link = RequirementProject::factory()->forVersion($version)->create([
            'version_assigned_by_id' => $actor->id,
            'version_assigned_at' => now(),
        ]);
        $other = RequirementProject::factory()->forVersion($version)->create();

        $updated = $this->service()->unassignRequirement($version, $link, 6, $actor);
        $link->refresh();
        $this->assertNull($link->project_version_id);
        $this->assertNull($link->version_assigned_by_id);
        $this->assertNull($link->version_assigned_at);
        $this->assertSame($version->id, $other->fresh()->project_version_id);
        $this->assertSame(7, $updated->lock_version);
        $history = ProjectVersionHistory::sole();
        $this->assertSame('requirement_removed', $history->event_type);
        $this->assertSame($version->id, $history->metadata['old_version_id']);
        $this->assertNull($history->metadata['new_version_id']);
    }

    public function test_unassign_requires_reason_in_testing_and_rejects_wrong_version(): void
    {
        $actor = User::factory()->internal()->create();
        $project = Project::factory()->create();
        $testing = ProjectVersion::factory()->for($project)->inTesting()->create(['lock_version' => 2]);
        $other = ProjectVersion::factory()->for($project)->create();
        $link = RequirementProject::factory()->forVersion($testing)->create();

        $this->conflict(
            fn () => $this->service()->unassignRequirement($testing, $link, 2, $actor),
            'FORCE_REASON_REQUIRED',
            422,
            ['reason' => ['A reason is required.']],
        );
        $this->conflict(
            fn () => $this->service()->unassignRequirement($other, $link, 1, $actor, 'Wrong'),
            'VERSION_PROJECT_MISMATCH',
            409,
            ['project_version_id' => [
                'expected' => $other->id,
                'current' => $testing->id,
            ]],
        );
        $this->assertSame($testing->id, $link->fresh()->project_version_id);
        $this->assertSame(2, $testing->fresh()->lock_version);
        $this->assertSame(1, $other->fresh()->lock_version);
    }

    public function test_delete_draft_requires_expected_lock_empty_scope_and_empty_history(): void
    {
        $actor = User::factory()->internal()->create();
        $stale = ProjectVersion::factory()->create(['lock_version' => 2]);
        $this->conflict(
            fn () => $this->service()->deleteDraft($stale, 1, $actor),
            'STALE_VERSION',
            409,
            ['lock_version' => ['current' => 2]],
        );

        $planned = ProjectVersion::factory()->create(['status' => 2]);
        $this->conflict(
            fn () => $this->service()->deleteDraft($planned, 1, $actor),
            'VERSION_LOCKED',
            409,
            ['status' => ['current' => 2]],
        );

        $scoped = ProjectVersion::factory()->create();
        RequirementProject::factory()->forVersion($scoped)->create();
        $this->conflict(
            fn () => $this->service()->deleteDraft($scoped, 1, $actor),
            'VERSION_LOCKED',
            409,
            ['reason' => ['requirements_assigned']],
        );

        $withHistory = ProjectVersion::factory()->create();
        ProjectVersionHistory::create([
            'project_version_id' => $withHistory->id,
            'event_type' => 'requirement_removed',
            'actor_id' => $actor->id,
            'metadata' => [],
            'created_at' => now(),
        ]);
        $this->conflict(
            fn () => $this->service()->deleteDraft($withHistory, 1, $actor),
            'VERSION_LOCKED',
            409,
            ['reason' => ['history_exists']],
        );

        $deletable = ProjectVersion::factory()->create();
        $this->service()->deleteDraft($deletable, 1, $actor);
        $this->assertModelMissing($deletable);
        $this->assertModelExists($stale);
        $this->assertModelExists($planned);
    }

    public function test_postgresql_row_lock_reports_database_lock_wait_before_stale_check(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $actor = User::factory()->internal()->create();
        $version = ProjectVersion::factory()->create(['name' => 'Original']);
        $script = sprintf(implode("\n", [
            'require getcwd()."/vendor/autoload.php";',
            '$app = require getcwd()."/bootstrap/app.php";',
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
            '$pid = (int) Illuminate\\Support\\Facades\\DB::selectOne("select pg_backend_pid() as pid")->pid;',
            'echo json_encode(["event" => "started", "pid" => $pid])."\\n"; flush();',
            'try {',
            '  app(App\\Services\\ProjectVersionService::class)->update(',
            '    App\\Models\\ProjectVersion::findOrFail(%d),',
            '    ["name" => "Child"], 1, App\\Models\\User::findOrFail(%d)',
            '  );',
            '  echo json_encode(["event" => "result", "result" => "updated"])."\\n";',
            '} catch (App\\Exceptions\\DomainConflictException $exception) {',
            '  echo json_encode(["event" => "result", "result" => "conflict", "code" => $exception->errorCode, "errors" => $exception->errors])."\\n";',
            '}',
        ]), $version->id, $actor->id);
        $process = new Process([PHP_BINARY, '-r', $script], base_path());
        $process->setTimeout(8);
        $observedWaitType = null;
        DB::beginTransaction();

        try {
            DB::table('project_versions')->where('id', $version->id)->lockForUpdate()->first();
            $process->start();
            $backendPid = $this->waitForProcessEvent($process, 'started')['pid'];
            $deadline = microtime(true) + 5;
            do {
                $activity = DB::selectOne(
                    'select wait_event_type, wait_event from pg_stat_activity where pid = ?',
                    [$backendPid],
                );
                $observedWaitType = $activity?->wait_event_type;
                if ($observedWaitType === 'Lock') {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            DB::table('project_versions')->where('id', $version->id)->update(['lock_version' => 2]);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $process->wait();
        $payload = $this->waitForProcessEvent($process, 'result');
        $this->assertSame('Lock', $observedWaitType);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame([
            'event' => 'result',
            'result' => 'conflict',
            'code' => 'STALE_VERSION',
            'errors' => ['lock_version' => ['current' => 2]],
        ], $payload);
        $this->assertSame('Original', $version->fresh()->name);
        $this->assertSame(2, $version->fresh()->lock_version);
    }

    public function test_opposite_scope_moves_finish_without_deadlock_and_version_both_sides(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $actor = User::factory()->internal()->create();
        $project = Project::factory()->create();
        $first = ProjectVersion::factory()->for($project)->create(['lock_version' => 1]);
        $second = ProjectVersion::factory()->for($project)->create(['lock_version' => 1]);
        $firstLink = RequirementProject::factory()->forVersion($first)->create();
        $secondLink = RequirementProject::factory()->forVersion($second)->create();
        $barrier = sys_get_temp_dir().'/ipms-scope-move-'.bin2hex(random_bytes(8));
        $firstProcess = new Process([PHP_BINARY, '-r', $this->scopeMoveScript(
            $second->id, $firstLink->id, $actor->id, $barrier,
        )], base_path());
        $secondProcess = new Process([PHP_BINARY, '-r', $this->scopeMoveScript(
            $first->id, $secondLink->id, $actor->id, $barrier,
        )], base_path());
        $firstProcess->setTimeout(12);
        $secondProcess->setTimeout(12);

        try {
            $firstProcess->start();
            $secondProcess->start();
            $firstStarted = $this->waitForProcessEvent($firstProcess, 'started');
            $secondStarted = $this->waitForProcessEvent($secondProcess, 'started');
            $this->assertNotSame($firstStarted['pid'], $secondStarted['pid']);
            touch($barrier);
            $firstProcess->wait();
            $secondProcess->wait();
        } finally {
            @unlink($barrier);
            if ($firstProcess->isRunning()) {
                $firstProcess->stop();
            }
            if ($secondProcess->isRunning()) {
                $secondProcess->stop();
            }
        }

        try {
            $firstResult = $this->waitForProcessEvent($firstProcess, 'result');
            $secondResult = $this->waitForProcessEvent($secondProcess, 'result');
            $this->assertTrue($firstProcess->isSuccessful(), $firstProcess->getErrorOutput());
            $this->assertTrue($secondProcess->isSuccessful(), $secondProcess->getErrorOutput());
            $this->assertSame('updated', $firstResult['result']);
            $this->assertSame('updated', $secondResult['result']);
            $this->assertNotContains('40P01', array_column([
                ...$this->processEvents($firstProcess),
                ...$this->processEvents($secondProcess),
            ], 'sql_state'));
            $this->assertSame($second->id, $firstLink->fresh()->project_version_id);
            $this->assertSame($first->id, $secondLink->fresh()->project_version_id);
            $this->assertSame(3, $first->fresh()->lock_version);
            $this->assertSame(3, $second->fresh()->lock_version);
            $this->assertDatabaseCount('project_version_histories', 2);
        } finally {
            DB::table('project_version_histories')
                ->whereIn('project_version_id', [$first->id, $second->id])
                ->delete();
            DB::table('requirement_project')
                ->whereIn('id', [$firstLink->id, $secondLink->id])
                ->delete();
            DB::table('requirements')
                ->whereIn('id', [$firstLink->requirement_id, $secondLink->requirement_id])
                ->delete();
            DB::table('project_versions')
                ->whereIn('id', [$first->id, $second->id])
                ->delete();
        }
    }

    private function scopeMoveScript(
        int $targetVersionId,
        int $linkId,
        int $actorId,
        string $barrier,
    ): string {
        return sprintf(implode("\n", [
            'require getcwd()."/vendor/autoload.php";',
            '$app = require getcwd()."/bootstrap/app.php";',
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
            '$pid = (int) Illuminate\\Support\\Facades\\DB::selectOne("select pg_backend_pid() as pid")->pid;',
            'echo json_encode(["event" => "started", "pid" => $pid])."\\n"; flush();',
            'while (! file_exists(%s)) { usleep(1000); }',
            '$expected = 1; $attempts = 0;',
            'while (true) {',
            '  $attempts++;',
            '  try {',
            '    $updated = app(App\\Services\\ProjectVersionService::class)->assignRequirement(',
            '      App\\Models\\ProjectVersion::findOrFail(%d),',
            '      App\\Models\\RequirementProject::findOrFail(%d),',
            '      $expected, App\\Models\\User::findOrFail(%d), "Concurrent move"',
            '    );',
            '    echo json_encode(["event" => "result", "result" => "updated", "attempts" => $attempts])."\\n";',
            '    break;',
            '  } catch (App\\Exceptions\\DomainConflictException $exception) {',
            '    if ($exception->errorCode === "STALE_VERSION" && $attempts < 3) {',
            '      $expected = $exception->errors["lock_version"]["current"];',
            '      continue;',
            '    }',
            '    echo json_encode(["event" => "result", "result" => "conflict", "code" => $exception->errorCode])."\\n";',
            '    break;',
            '  } catch (Illuminate\\Database\\QueryException $exception) {',
            '    echo json_encode(["event" => "result", "result" => "error", "sql_state" => $exception->getCode()])."\\n";',
            '    break;',
            '  }',
            '}',
        ]), var_export($barrier, true), $targetVersionId, $linkId, $actorId);
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForProcessEvent(Process $process, string $event): array
    {
        $deadline = microtime(true) + 8;
        do {
            foreach ($this->processEvents($process) as $payload) {
                if (($payload['event'] ?? null) === $event) {
                    return $payload;
                }
            }
            usleep(10_000);
        } while ($process->isRunning() && microtime(true) < $deadline);

        foreach ($this->processEvents($process) as $payload) {
            if (($payload['event'] ?? null) === $event) {
                return $payload;
            }
        }
        $this->fail("Process event [{$event}] was not emitted. Output: {$process->getOutput()}");

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function processEvents(Process $process): array
    {
        return collect(explode("\n", trim($process->getOutput())))
            ->filter()
            ->map(fn (string $line): mixed => json_decode($line, true))
            ->filter(fn (mixed $payload): bool => is_array($payload))
            ->values()
            ->all();
    }

    public function test_owner_nullable_migration_rolls_back_safely_and_reapplies(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $actor = User::factory()->internal()->create();
        $version = $this->service()->create(
            Project::factory()->create(),
            [
                'code' => 'ROLLBACK-OWNER',
                'name' => 'Rollback owner safety',
                'owner_id' => null,
            ],
            $actor,
        );
        $this->assertNull($version->owner_id);

        $migration = require database_path(
            'migrations/2026_08_25_000030_allow_draft_project_version_without_owner.php',
        );

        try {
            $migration->down();

            $this->assertSame($actor->id, $version->fresh()->owner_id);
            $this->assertSame('NO', $this->ownerColumnNullable());

            $migration->up();

            $this->assertSame('YES', $this->ownerColumnNullable());
            DB::table('project_versions')
                ->where('id', $version->id)
                ->update(['owner_id' => null]);
            $this->assertNull($version->fresh()->owner_id);
        } finally {
            if ($this->ownerColumnNullable() !== 'YES') {
                $migration->up();
            }
        }
    }

    private function ownerColumnNullable(): string
    {
        return DB::selectOne(<<<'SQL'
            SELECT is_nullable
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'project_versions'
              AND column_name = 'owner_id'
            SQL)->is_nullable;
    }

    private function service(): ProjectVersionService
    {
        return app(ProjectVersionService::class);
    }

    /**
     * @param  callable(): mixed  $operation
     * @param  array<string, mixed>  $errors
     */
    private function conflict(callable $operation, string $code, int $status, array $errors): void
    {
        try {
            $operation();
            $this->fail("Expected domain conflict [{$code}].");
        } catch (DomainConflictException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
            $this->assertSame($errors, $exception->errors);
        }
    }
}
