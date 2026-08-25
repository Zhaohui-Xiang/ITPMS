<?php

namespace Tests\Feature\Services;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\RequirementStatus;
use App\Enums\TaskStatus;
use App\Events\ProjectVersionReleased;
use App\Exceptions\DomainConflictException;
use App\Models\AuditLog;
use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\ProjectVersionHistory;
use App\Models\ProjectVersionReleaseSnapshot;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectReleaseService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProjectReleaseServiceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_normal_release_updates_state_and_records_snapshot_history_audit_and_event(): void
    {
        Event::fake([ProjectVersionReleased::class]);
        [$manager, $project] = $this->managedProject();
        $version = ProjectVersion::factory()->for($project)->inTesting()->withPassingScope()->create([
            'lock_version' => 5,
        ]);
        $link = $version->requirementLinks()->sole();
        $other = RequirementProject::factory()->for($link->requirement)->create([
            'delivery_status' => ProjectDeliveryStatus::ACCEPTED->value,
        ]);
        Task::factory()->forVersionScope($version)->create([
            'status' => TaskStatus::COMPLETED->value,
            'completed_at' => now(),
        ]);
        Defect::factory()->forVersionScope($version)->create([
            'severity' => DefectSeverity::MINOR->value,
            'status' => DefectStatus::REOPENED->value,
        ]);
        DB::table('project_versions')->where('id', $version->id)->update([
            'status' => ProjectVersionStatus::READY_TO_RELEASE->value,
        ]);

        $released = $this->release($version, $manager, 5, '  Completed release  ');

        $this->assertSame(ProjectVersionStatus::RELEASED, $released->status);
        $this->assertSame(6, $released->lock_version);
        $this->assertSame($manager->id, $released->released_by_id);
        $this->assertSame('Completed release', $released->release_notes);
        $this->assertSame(ProjectDeliveryStatus::DEPLOYED, $link->fresh()->delivery_status);
        $this->assertSame(ProjectDeliveryStatus::ACCEPTED, $other->fresh()->delivery_status);
        $this->assertSame(RequirementStatus::DEPLOYED->value, $link->requirement->fresh()->status);

        $snapshot = ProjectVersionReleaseSnapshot::query()->sole();
        $this->assertEquals([[
            'requirement_project_id' => $link->id,
            'requirement_id' => $link->requirement_id,
            'project_id' => $project->id,
            'delivery_status' => ProjectDeliveryStatus::DEPLOYED->value,
        ]], $snapshot->requirement_scope);
        $this->assertSame(2, $snapshot->task_count);
        $this->assertSame(2, $snapshot->defect_count);
        $this->assertTrue($snapshot->gate_result['passed']);
        $this->assertFalse($snapshot->is_override);
        $this->assertSame($manager->id, $snapshot->released_by_id);
        $this->assertTrue($released->released_at->equalTo($snapshot->released_at));

        $history = ProjectVersionHistory::query()->sole();
        $this->assertSame('release', $history->event_type);
        $this->assertSame(ProjectVersionStatus::READY_TO_RELEASE, $history->from_status);
        $this->assertSame(ProjectVersionStatus::RELEASED, $history->to_status);
        $audit = AuditLog::query()->sole();
        $this->assertSame('project_version', $audit->target_type);
        $this->assertSame('release', $audit->detail['event']);
        Event::assertDispatchedTimes(ProjectVersionReleased::class, 1);
        Event::assertDispatched(ProjectVersionReleased::class, fn ($event): bool => $event->projectVersionId === $version->id
            && $event->projectId === $project->id
            && $event->releasedById === $manager->id
            && $event->isOverride === false
        );
    }

    public function test_normal_release_enforces_actor_status_lock_and_live_gates_without_writes(): void
    {
        Event::fake([ProjectVersionReleased::class]);
        [$manager, $project] = $this->managedProject();
        foreach ([$this->userWithRole('it_pm'), $this->userWithRole('super_admin')] as $actor) {
            $version = ProjectVersion::factory()->for($project)->ready()->withPassingScope()->create();
            $this->conflict(fn () => $this->release($version, $actor), 'VERSION_RELEASE_FORBIDDEN', 403, [
                'actor_id' => [$actor->id],
            ]);
            $this->assertUntouched($version);
        }

        $testing = ProjectVersion::factory()->for($project)->inTesting()->withPassingScope()->create();
        $this->conflict(fn () => $this->release($testing, $manager), 'INVALID_RELEASE_STATUS', 409, [
            'status' => ['current' => 4, 'allowed' => [5]],
        ]);
        $this->assertUntouched($testing);

        $stale = ProjectVersion::factory()->for($project)->ready()->withPassingScope()->create([
            'lock_version' => 3,
        ]);
        $this->conflict(fn () => $this->release($stale, $manager, 2), 'STALE_VERSION', 409, [
            'lock_version' => ['current' => 3],
        ]);
        $this->assertUntouched($stale, 3);

        $blocked = ProjectVersion::factory()->for($project)->inTesting()->create(['release_notes' => null]);
        $link = RequirementProject::factory()->forVersion($blocked)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_TESTING->value,
        ]);
        Task::factory()->forVersionScope($blocked)->create(['status' => TaskStatus::TODO->value]);
        Defect::factory()->forVersionScope($blocked)->create([
            'severity' => DefectSeverity::FATAL->value,
            'status' => DefectStatus::CONFIRMED->value,
        ]);
        DB::table('project_versions')->where('id', $blocked->id)->update([
            'status' => ProjectVersionStatus::READY_TO_RELEASE->value,
        ]);
        try {
            $this->release($blocked, $manager, 1, ' ');
            $this->fail('Expected gate failure.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('RELEASE_GATE_FAILED', $exception->errorCode);
            $this->assertSame([
                'project_delivery',
                'tasks_completed',
                'severe_defects_closed',
                'release_notes_present',
            ], array_column($exception->errors, 'code'));
        }
        $this->assertSame(ProjectDeliveryStatus::IN_TESTING, $link->fresh()->delivery_status);
        $this->assertUntouched($blocked);
        Event::assertNotDispatched(ProjectVersionReleased::class);
    }

    public function test_force_release_enforces_actor_reason_and_status_with_zero_writes(): void
    {
        Event::fake([ProjectVersionReleased::class]);
        [$manager, $project] = $this->managedProject();
        $admin = $this->userWithRole('super_admin');
        $version = ProjectVersion::factory()->for($project)->inTesting()->create();
        $this->conflict(
            fn () => $this->release($version, $manager, force: true, reason: 'Emergency'),
            'FORCE_RELEASE_FORBIDDEN',
            403,
            ['actor_id' => [$manager->id]],
        );
        $this->assertUntouched($version);

        $reasonless = ProjectVersion::factory()->for($project)->inTesting()->create();
        $this->conflict(
            fn () => $this->release($reasonless, $admin, force: true, reason: '  '),
            'FORCE_REASON_REQUIRED',
            422,
            ['reason' => ['A reason is required.']],
        );
        $this->assertUntouched($reasonless);

        foreach ([1, 2, 3, 7] as $status) {
            $invalid = ProjectVersion::factory()->for($project)->create(['status' => $status]);
            $this->conflict(
                fn () => $this->release($invalid, $admin, force: true, reason: 'Emergency'),
                'INVALID_FORCE_RELEASE_STATUS',
                409,
                ['status' => ['current' => $status, 'allowed' => [4, 5]]],
            );
            $this->assertUntouched($invalid, 1, ProjectVersionStatus::from($status));
        }

        $releasedWithoutSnapshot = ProjectVersion::factory()->for($project)->create([
            'status' => ProjectVersionStatus::RELEASED,
        ]);
        $this->conflict(
            fn () => $this->release(
                $releasedWithoutSnapshot,
                $admin,
                force: true,
                reason: 'Emergency',
            ),
            'RELEASE_SNAPSHOT_MISSING',
            409,
            ['project_version_id' => [$releasedWithoutSnapshot->id]],
        );
        $this->assertUntouched(
            $releasedWithoutSnapshot,
            1,
            ProjectVersionStatus::RELEASED,
        );
        Event::assertNotDispatched(ProjectVersionReleased::class);
    }

    public function test_force_release_records_original_status_all_failed_gates_and_reason(): void
    {
        Event::fake([ProjectVersionReleased::class]);
        [$manager, $project] = $this->managedProject();
        $admin = $this->userWithRole('super_admin');
        $version = ProjectVersion::factory()->for($project)->inTesting()->create([
            'lock_version' => 4,
            'release_notes' => null,
        ]);
        $link = RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_DEVELOPMENT->value,
        ]);
        Task::factory()->forVersionScope($version)->create(['status' => TaskStatus::TODO->value]);
        Defect::factory()->forVersionScope($version)->create([
            'severity' => DefectSeverity::SERIOUS->value,
            'status' => DefectStatus::FIXING->value,
        ]);

        $released = $this->release($version, $admin, 4, 'Patch', true, '  Customer outage  ');

        $this->assertSame(ProjectVersionStatus::RELEASED, $released->status);
        $snapshot = ProjectVersionReleaseSnapshot::query()->sole();
        $this->assertTrue($snapshot->is_override);
        $this->assertSame('Customer outage', $snapshot->override_reason);
        $this->assertSame(4, $snapshot->gate_result['original_status']);
        $this->assertFalse($snapshot->gate_result['passed']);
        $failed = array_column($snapshot->gate_result['blocking'], 'code');
        $this->assertSame(['project_delivery', 'tasks_completed', 'severe_defects_closed'], $failed);
        $history = ProjectVersionHistory::query()->sole();
        $this->assertSame('force_release', $history->event_type);
        $this->assertSame(ProjectVersionStatus::IN_TESTING, $history->from_status);
        $this->assertSame('Customer outage', $history->reason);
        $this->assertSame($failed, array_column($history->metadata['failed_gates'], 'code'));
        $audit = AuditLog::query()->sole();
        $this->assertSame('force_release', $audit->detail['event']);
        $this->assertSame('Customer outage', $audit->detail['override_reason']);
        Event::assertDispatchedTimes(ProjectVersionReleased::class, 1);
    }

    public function test_release_is_idempotent_only_when_snapshot_exists(): void
    {
        Event::fake([ProjectVersionReleased::class]);
        [$manager, $project] = $this->managedProject();
        $version = ProjectVersion::factory()->for($project)->ready()->withPassingScope()->create([
            'lock_version' => 7,
        ]);
        $first = $this->release($version, $manager, 7);
        $before = [
            $first->lock_version,
            $first->released_at->toISOString(),
            ProjectVersionHistory::count(),
            AuditLog::count(),
            ProjectVersionReleaseSnapshot::count(),
        ];
        $second = $this->release($version, $manager, 7, 'Ignored');
        $this->assertSame($before, [
            $second->lock_version,
            $second->released_at->toISOString(),
            ProjectVersionHistory::count(),
            AuditLog::count(),
            ProjectVersionReleaseSnapshot::count(),
        ]);
        Event::assertDispatchedTimes(ProjectVersionReleased::class, 1);

        $broken = ProjectVersion::factory()->for($project)->create([
            'status' => 6,
            'released_at' => now(),
            'released_by_id' => $manager->id,
            'lock_version' => 9,
        ]);
        $this->conflict(fn () => $this->release($broken, $manager, 9), 'RELEASE_SNAPSHOT_MISSING', 409, [
            'project_version_id' => [$broken->id],
        ]);
    }

    public function test_snapshot_update_and_delete_throw_and_preserve_stored_content(): void
    {
        [$manager, $project] = $this->managedProject();
        $version = ProjectVersion::factory()->for($project)->ready()->withPassingScope()->create();
        $this->release($version, $manager);
        $snapshot = ProjectVersionReleaseSnapshot::query()->sole();
        $stored = $snapshot->getRawOriginal();

        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update'
                    ? $snapshot->update(['release_notes' => 'Changed'])
                    : $snapshot->delete();
                $this->fail("Expected snapshot {$operation} to fail.");
            } catch (LogicException $exception) {
                $this->assertSame('Project version release snapshots are immutable.', $exception->getMessage());
            }
            $this->assertSame($stored, $snapshot->fresh()->getRawOriginal());
        }
    }

    public function test_event_dispatches_after_outer_commit_only_and_not_after_rollback(): void
    {
        Event::fake([ProjectVersionReleased::class]);
        [$manager, $project] = $this->managedProject();
        $committed = ProjectVersion::factory()->for($project)->ready()->withPassingScope()->create();
        DB::beginTransaction();
        $this->release($committed, $manager);
        Event::assertNotDispatched(ProjectVersionReleased::class);
        DB::commit();
        Event::assertDispatchedTimes(ProjectVersionReleased::class, 1);

        $rolledBack = ProjectVersion::factory()->for($project)->ready()->withPassingScope()->create();
        DB::beginTransaction();
        $this->release($rolledBack, $manager);
        DB::rollBack();
        Event::assertDispatchedTimes(ProjectVersionReleased::class, 1);
        $this->assertSame(ProjectVersionStatus::READY_TO_RELEASE, $rolledBack->fresh()->status);
        $this->assertDatabaseMissing('project_version_release_snapshots', [
            'project_version_id' => $rolledBack->id,
        ]);
    }

    public function test_real_database_failure_at_each_write_stage_rolls_back_every_write(): void
    {
        Event::fake([ProjectVersionReleased::class]);
        [$manager, $project] = $this->managedProject();
        foreach ([
            'pivot' => ['requirement_project', 'UPDATE'],
            'aggregate' => ['requirements', 'UPDATE'],
            'snapshot' => ['project_version_release_snapshots', 'INSERT'],
            'history' => ['project_version_histories', 'INSERT'],
            'audit' => ['audit_logs', 'INSERT'],
        ] as $stage => [$table, $operation]) {
            $version = ProjectVersion::factory()->for($project)->ready()->withPassingScope()->create();
            $link = $version->requirementLinks()->sole();
            $this->installFailureTrigger($stage, $table, $operation);
            try {
                $this->release($version, $manager);
                $this->fail("Expected {$stage} failure.");
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->getCode(), $stage);
            } finally {
                $this->dropFailureTrigger($stage, $table);
            }

            $this->assertSame(ProjectVersionStatus::READY_TO_RELEASE, $version->fresh()->status, $stage);
            $this->assertSame(1, $version->fresh()->lock_version, $stage);
            $this->assertSame(ProjectDeliveryStatus::PENDING_DEPLOY, $link->fresh()->delivery_status, $stage);
            $this->assertSame(RequirementStatus::ASSIGNED->value, $link->requirement->fresh()->status, $stage);
            $this->assertDatabaseMissing('project_version_release_snapshots', ['project_version_id' => $version->id]);
            $this->assertDatabaseMissing('project_version_histories', ['project_version_id' => $version->id]);
            $this->assertDatabaseMissing('audit_logs', [
                'target_type' => 'project_version',
                'target_id' => (string) $version->id,
            ]);
        }
        Event::assertNotDispatched(ProjectVersionReleased::class);
    }

    public function test_concurrent_scope_assignment_and_force_release_do_not_deadlock_or_leave_late_scope(): void
    {
        [$manager, $project] = $this->managedProject();
        $admin = $this->userWithRole('super_admin');
        $version = ProjectVersion::factory()->for($project)->inTesting()->create();
        RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_TESTING->value,
        ]);
        $candidate = RequirementProject::factory()->create([
            'project_id' => $project->id,
            'project_version_id' => null,
            'delivery_status' => ProjectDeliveryStatus::IN_TESTING->value,
        ]);
        $barrier = sys_get_temp_dir().'/ipms-release-race-'.bin2hex(random_bytes(8));
        $release = new Process([PHP_BINARY, '-r', $this->releaseScript(
            $version->id, $admin->id, $barrier,
        )], base_path());
        $assign = new Process([PHP_BINARY, '-r', $this->assignScript(
            $version->id, $candidate->id, $manager->id, $barrier,
        )], base_path());
        $release->setTimeout(15);
        $assign->setTimeout(15);

        try {
            $release->start();
            $assign->start();
            usleep(100_000);
            touch($barrier);
            $release->wait();
            $assign->wait();
        } finally {
            @unlink($barrier);
            if ($release->isRunning()) {
                $release->stop();
            }
            if ($assign->isRunning()) {
                $assign->stop();
            }
        }

        $releaseResult = $this->lastProcessPayload($release);
        $assignResult = $this->lastProcessPayload($assign);
        $this->assertTrue($release->isSuccessful(), $release->getErrorOutput());
        $this->assertTrue($assign->isSuccessful(), $assign->getErrorOutput());
        $this->assertSame('released', $releaseResult['result']);
        $this->assertContains($assignResult['result'], ['assigned', 'VERSION_LOCKED', 'STALE_VERSION']);
        $this->assertNotSame('40P01', $releaseResult['sql_state'] ?? null);
        $this->assertNotSame('40P01', $assignResult['sql_state'] ?? null);
        $this->assertSame(ProjectVersionStatus::RELEASED, $version->fresh()->status);

        $candidate->refresh();
        if ($candidate->project_version_id === $version->id) {
            $this->assertSame(ProjectDeliveryStatus::DEPLOYED, $candidate->delivery_status);
            $snapshot = ProjectVersionReleaseSnapshot::query()
                ->where('project_version_id', $version->id)
                ->sole();
            $this->assertContains(
                $candidate->id,
                array_column($snapshot->requirement_scope, 'requirement_project_id'),
            );
        } else {
            $this->assertNull($candidate->project_version_id);
        }
    }

    public function test_concurrent_scope_move_and_force_release_use_the_same_lock_order(): void
    {
        [$manager, $project] = $this->managedProject();
        $admin = $this->userWithRole('super_admin');
        $target = ProjectVersion::factory()->for($project)->inTesting()->create();
        $source = ProjectVersion::factory()->for($project)->inTesting()->create();
        RequirementProject::factory()->forVersion($target)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_TESTING->value,
        ]);
        $moving = RequirementProject::factory()->forVersion($source)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_TESTING->value,
        ]);
        $barrier = sys_get_temp_dir().'/ipms-release-move-'.bin2hex(random_bytes(8));
        $release = new Process([PHP_BINARY, '-r', $this->releaseScript(
            $target->id, $admin->id, $barrier,
        )], base_path());
        $move = new Process([PHP_BINARY, '-r', $this->assignScript(
            $target->id, $moving->id, $manager->id, $barrier,
        )], base_path());
        $release->setTimeout(15);
        $move->setTimeout(15);

        try {
            $release->start();
            $move->start();
            usleep(100_000);
            touch($barrier);
            $release->wait();
            $move->wait();
        } finally {
            @unlink($barrier);
            if ($release->isRunning()) {
                $release->stop();
            }
            if ($move->isRunning()) {
                $move->stop();
            }
        }

        $releaseResult = $this->lastProcessPayload($release);
        $moveResult = $this->lastProcessPayload($move);
        $this->assertTrue($release->isSuccessful(), $release->getErrorOutput());
        $this->assertTrue($move->isSuccessful(), $move->getErrorOutput());
        $this->assertSame('released', $releaseResult['result']);
        $this->assertContains($moveResult['result'], ['assigned', 'VERSION_LOCKED', 'STALE_VERSION']);
        $this->assertNotSame('40P01', $releaseResult['sql_state'] ?? null);
        $this->assertNotSame('40P01', $moveResult['sql_state'] ?? null);
        $this->assertSame(ProjectVersionStatus::RELEASED, $target->fresh()->status);

        $moving->refresh();
        if ($moving->project_version_id === $target->id) {
            $this->assertSame(ProjectDeliveryStatus::DEPLOYED, $moving->delivery_status);
            $this->assertContains(
                $moving->id,
                array_column(
                    $target->releaseSnapshot()->sole()->requirement_scope,
                    'requirement_project_id',
                ),
            );
        } else {
            $this->assertSame($source->id, $moving->project_version_id);
        }
    }

    public function test_force_release_replay_is_idempotent_and_missing_snapshot_is_invariant_error(): void
    {
        Event::fake([ProjectVersionReleased::class]);
        [$manager, $project] = $this->managedProject();
        $admin = $this->userWithRole('super_admin');
        $version = ProjectVersion::factory()->for($project)->inTesting()->withPassingScope()->create([
            'lock_version' => 4,
        ]);

        $first = $this->release($version, $admin, 4, 'Patch', true, 'Emergency');
        $before = [
            $first->lock_version,
            $first->released_at->toISOString(),
            ProjectVersionHistory::count(),
            AuditLog::count(),
            ProjectVersionReleaseSnapshot::count(),
        ];

        $second = $this->release($version, $admin, 4, 'Ignored', true, null);

        $this->assertSame($before, [
            $second->lock_version,
            $second->released_at->toISOString(),
            ProjectVersionHistory::count(),
            AuditLog::count(),
            ProjectVersionReleaseSnapshot::count(),
        ]);
        Event::assertDispatchedTimes(ProjectVersionReleased::class, 1);

        $broken = ProjectVersion::factory()->for($project)->create([
            'status' => ProjectVersionStatus::RELEASED,
            'released_at' => now(),
            'released_by_id' => $admin->id,
        ]);
        $this->conflict(
            fn () => $this->release($broken, $admin, force: true, reason: null),
            'RELEASE_SNAPSHOT_MISSING',
            409,
            ['project_version_id' => [$broken->id]],
        );
    }

    public function test_idempotent_release_still_requires_the_snapshot_mode_actor(): void
    {
        [$manager, $project] = $this->managedProject();
        $admin = $this->userWithRole('super_admin');
        $otherManager = $this->userWithRole('it_pm');
        $normal = ProjectVersion::factory()->for($project)->ready()->withPassingScope()->create();
        $this->release($normal, $manager);

        $this->conflict(
            fn () => $this->release($normal, $otherManager),
            'VERSION_RELEASE_FORBIDDEN',
            403,
            ['actor_id' => [$otherManager->id]],
        );
        $this->conflict(
            fn () => $this->release($normal, $admin, force: true, reason: null),
            'RELEASE_MODE_MISMATCH',
            409,
            ['force' => ['expected' => false]],
        );
    }

    public function test_release_command_rejects_coerced_force_and_lock_types(): void
    {
        [$manager, $project] = $this->managedProject();
        $version = ProjectVersion::factory()->for($project)->ready()->withPassingScope()->create();

        foreach ([
            ['force' => 'false', 'lock_version' => 1, 'field' => 'force'],
            ['force' => false, 'lock_version' => '1', 'field' => 'lock_version'],
        ] as $case) {
            try {
                $this->service()->release($version, $manager, [
                    'force' => $case['force'],
                    'lock_version' => $case['lock_version'],
                    'release_notes' => 'Release',
                ]);
                $this->fail('Expected strict release command validation.');
            } catch (DomainConflictException $exception) {
                $this->assertSame('INVALID_RELEASE_COMMAND', $exception->errorCode);
                $this->assertSame(422, $exception->status);
                $this->assertArrayHasKey($case['field'], $exception->errors);
            }
        }
        $this->assertUntouched($version);
    }

    public function test_snapshot_database_rows_are_immutable_and_direct_model_create_is_forbidden(): void
    {
        [$manager, $project] = $this->managedProject();
        $version = ProjectVersion::factory()->for($project)->ready()->withPassingScope()->create();
        $this->release($version, $manager);
        $snapshot = ProjectVersionReleaseSnapshot::query()->sole();
        $stored = $snapshot->getRawOriginal();

        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update'
                    ? DB::table('project_version_release_snapshots')
                        ->where('id', $snapshot->id)
                        ->update(['release_notes' => 'Bypass'])
                    : DB::table('project_version_release_snapshots')
                        ->where('id', $snapshot->id)
                        ->delete();
                $this->fail("Expected database snapshot {$operation} to fail.");
            } catch (QueryException $exception) {
                $this->assertSame('IV002', $exception->getCode());
            }
            $this->assertSame($stored, $snapshot->fresh()->getRawOriginal());
        }

        $unreleased = ProjectVersion::factory()->for($project)->ready()->create();
        try {
            ProjectVersionReleaseSnapshot::query()->create([
                'project_version_id' => $unreleased->id,
                'requirement_scope' => [],
                'task_count' => 0,
                'defect_count' => 0,
                'gate_result' => [],
                'is_override' => false,
                'released_by_id' => $manager->id,
                'released_at' => now(),
            ]);
            $this->fail('Expected direct release snapshot creation to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Project version release snapshots may only be created by the release service.',
                $exception->getMessage(),
            );
        }
    }

    private function managedProject(): array
    {
        $manager = $this->userWithRole('it_pm');

        return [$manager, Project::factory()->withManager($manager)->create()];
    }

    private function userWithRole(string $code): User
    {
        return User::factory()->withRole($code)->create();
    }

    private function release(
        ProjectVersion $version,
        User $actor,
        int $lock = 1,
        string $notes = 'Release notes',
        bool $force = false,
        ?string $reason = null,
    ): ProjectVersion {
        return $this->service()->release($version, $actor, [
            'lock_version' => $lock,
            'release_notes' => $notes,
            'force' => $force,
            'force_reason' => $reason,
        ]);
    }

    private function service(): ProjectReleaseService
    {
        return app(ProjectReleaseService::class);
    }

    private function assertUntouched(
        ProjectVersion $version,
        int $lock = 1,
        ?ProjectVersionStatus $expectedStatus = null,
    ): void {
        $version->refresh();
        if ($expectedStatus === null) {
            $this->assertNotSame(ProjectVersionStatus::RELEASED, $version->status);
        } else {
            $this->assertSame($expectedStatus, $version->status);
        }
        $this->assertSame($lock, $version->lock_version);
        $this->assertNull($version->released_at);
        $this->assertDatabaseMissing('project_version_release_snapshots', ['project_version_id' => $version->id]);
        $this->assertDatabaseMissing('project_version_histories', ['project_version_id' => $version->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'target_type' => 'project_version',
            'target_id' => (string) $version->id,
        ]);
    }

    private function conflict(callable $operation, string $code, int $status, array $errors): void
    {
        try {
            $operation();
            $this->fail("Expected conflict {$code}.");
        } catch (DomainConflictException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
            $this->assertSame($errors, $exception->errors);
        }
    }

    private function installFailureTrigger(string $stage, string $table, string $operation): void
    {
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION ipms_test_reject_release_{$stage}()
RETURNS trigger AS \$\$
BEGIN
    RAISE EXCEPTION 'forced release {$stage} failure';
END;
\$\$ LANGUAGE plpgsql;
CREATE TRIGGER ipms_test_reject_release_{$stage}
BEFORE {$operation} ON {$table}
FOR EACH ROW EXECUTE FUNCTION ipms_test_reject_release_{$stage}();
SQL);
    }

    private function releaseScript(int $versionId, int $actorId, string $barrier): string
    {
        return sprintf(implode("\n", [
            'require getcwd()."/vendor/autoload.php";',
            '$app=require getcwd()."/bootstrap/app.php";',
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
            'while (!file_exists(%s)) { usleep(1000); }',
            '$expected=1;',
            'for ($attempt=0; $attempt<4; $attempt++) {',
            ' try {',
            '  $v=app(App\\Services\\ProjectReleaseService::class)->release(',
            '   App\\Models\\ProjectVersion::findOrFail(%d), App\\Models\\User::findOrFail(%d),',
            '   ["lock_version"=>$expected,"release_notes"=>"Race","force"=>true,"force_reason"=>"Race"]',
            '  );',
            '  echo json_encode(["result"=>"released","lock"=>$v->lock_version])."\\n"; break;',
            ' } catch (App\\Exceptions\\DomainConflictException $e) {',
            '  if ($e->errorCode==="STALE_VERSION" && $attempt<3) { $expected=$e->errors["lock_version"]["current"]; continue; }',
            '  echo json_encode(["result"=>$e->errorCode])."\\n"; break;',
            ' } catch (Illuminate\\Database\\QueryException $e) {',
            '  echo json_encode(["result"=>"db_error","sql_state"=>$e->getCode()])."\\n"; break;',
            ' }',
            '}',
        ]), var_export($barrier, true), $versionId, $actorId);
    }

    private function assignScript(
        int $versionId,
        int $linkId,
        int $actorId,
        string $barrier,
    ): string {
        return sprintf(implode("\n", [
            'require getcwd()."/vendor/autoload.php";',
            '$app=require getcwd()."/bootstrap/app.php";',
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
            'while (!file_exists(%s)) { usleep(1000); }',
            '$expected=1;',
            'for ($attempt=0; $attempt<3; $attempt++) {',
            ' try {',
            '  app(App\\Services\\ProjectVersionService::class)->assignRequirement(',
            '   App\\Models\\ProjectVersion::findOrFail(%d),',
            '   App\\Models\\RequirementProject::findOrFail(%d),',
            '   $expected, App\\Models\\User::findOrFail(%d), "Race"',
            '  );',
            '  echo json_encode(["result"=>"assigned"])."\\n"; break;',
            ' } catch (App\\Exceptions\\DomainConflictException $e) {',
            '  if ($e->errorCode==="STALE_VERSION" && $attempt<2) { $expected=$e->errors["lock_version"]["current"]; continue; }',
            '  echo json_encode(["result"=>$e->errorCode])."\\n"; break;',
            ' } catch (Illuminate\\Database\\QueryException $e) {',
            '  echo json_encode(["result"=>"db_error","sql_state"=>$e->getCode()])."\\n"; break;',
            ' }',
            '}',
        ]), var_export($barrier, true), $versionId, $linkId, $actorId);
    }

    private function lastProcessPayload(Process $process): array
    {
        $payloads = collect(explode("\n", trim($process->getOutput())))
            ->filter()
            ->map(fn (string $line): mixed => json_decode($line, true))
            ->filter(fn (mixed $payload): bool => is_array($payload))
            ->values();

        $this->assertNotEmpty($payloads, $process->getOutput().$process->getErrorOutput());

        return $payloads->last();
    }

    private function dropFailureTrigger(string $stage, string $table): void
    {
        DB::unprepared(
            "DROP TRIGGER IF EXISTS ipms_test_reject_release_{$stage} ON {$table};"
            ."DROP FUNCTION IF EXISTS ipms_test_reject_release_{$stage}();"
        );
    }
}
