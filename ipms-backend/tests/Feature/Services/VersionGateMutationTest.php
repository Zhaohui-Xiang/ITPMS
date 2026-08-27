<?php

namespace Tests\Feature\Services;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\TaskStatus;
use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use App\Services\VersionGateLock;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VersionGateMutationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_ready_version_rejects_all_task_and_defect_write_shapes_at_database_boundary(): void
    {
        $version = ProjectVersion::factory()->inTesting()->create();
        $link = RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY,
        ]);
        $task = Task::factory()->forVersionScope($version)->create([
            'status' => TaskStatus::COMPLETED,
        ]);
        $defect = Defect::factory()->forVersionScope($version)->create([
            'severity' => DefectSeverity::SERIOUS,
            'status' => DefectStatus::CLOSED,
        ]);
        DB::table('project_versions')->where('id', $version->id)->update([
            'status' => ProjectVersionStatus::READY_TO_RELEASE->value,
        ]);

        $operations = [
            'task_insert' => fn () => Task::factory()->forVersionScope($version)->create(),
            'task_update' => fn () => $task->update(['title' => 'Locked update']),
            'task_delete' => fn () => $task->delete(),
            'defect_insert' => fn () => Defect::factory()->forVersionScope($version)->create(),
            'defect_update' => fn () => $defect->update(['status' => DefectStatus::REOPENED]),
            'defect_delete' => fn () => $defect->delete(),
        ];

        foreach ($operations as $name => $operation) {
            try {
                $operation();
                $this->fail("Expected {$name} to be rejected.");
            } catch (QueryException $exception) {
                $this->assertSame('IV001', $exception->getCode(), $name);
                $conflict = VersionGateLock::mutationConflict($exception);
                $this->assertNotNull($conflict, $name);
                $this->assertSame('VERSION_LOCKED', $conflict->errorCode, $name);
                $this->assertSame(409, $conflict->status, $name);
                $this->assertSame([
                    'project_version_id' => [$version->id],
                    'status' => ['current' => ProjectVersionStatus::READY_TO_RELEASE->value],
                ], $conflict->errors, $name);
            }
        }

        $this->assertModelExists($task);
        $this->assertModelExists($defect);
        $this->assertSame($link->id, $version->requirementLinks()->sole()->id);
    }

    public function test_in_testing_scope_still_allows_task_and_defect_mutations(): void
    {
        $version = ProjectVersion::factory()->inTesting()->create();
        RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_TESTING,
        ]);

        $task = Task::factory()->forVersionScope($version)->create([
            'status' => TaskStatus::TODO,
        ]);
        $defect = Defect::factory()->forVersionScope($version)->create([
            'severity' => DefectSeverity::SERIOUS,
            'status' => DefectStatus::CLOSED,
        ]);

        $task->update(['status' => TaskStatus::IN_PROGRESS]);
        $defect->update(['status' => DefectStatus::REOPENED]);

        $this->assertSame(TaskStatus::IN_PROGRESS->value, $task->fresh()->status);
        $this->assertSame(DefectStatus::REOPENED->value, $defect->fresh()->status);
    }

    public function test_api_maps_only_the_gate_trigger_sqlstate_to_stable_version_locked_conflict(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()->for($project)->inTesting()->create();
        $link = RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY,
        ]);
        DB::table('project_versions')->where('id', $version->id)->update([
            'status' => ProjectVersionStatus::READY_TO_RELEASE->value,
        ]);

        $response = $this->actingAs($manager)->postJson('/api/tasks', [
            'requirement_id' => $link->requirement_id,
            'project_id' => $project->id,
            'title' => 'Late blocker',
            'priority' => 2,
            'due_date' => '2026-10-30',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error_code', 'VERSION_LOCKED')
            ->assertJsonPath('errors.project_version_id.0', $version->id)
            ->assertJsonPath('errors.status.current', ProjectVersionStatus::READY_TO_RELEASE->value);
        $this->assertDatabaseMissing('tasks', ['title' => 'Late blocker']);
    }

    public function test_ready_version_rejects_direct_requirement_scope_assignment(): void
    {
        $version = ProjectVersion::factory()->ready()->withPassingScope()->create();
        $link = RequirementProject::factory()->create([
            'project_id' => $version->project_id,
            'project_version_id' => null,
        ]);

        try {
            DB::table('requirement_project')->where('id', $link->id)->update([
                'project_version_id' => $version->id,
            ]);
            $this->fail('Expected direct scope assignment to be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame('IV001', $exception->getCode());
            $conflict = VersionGateLock::mutationConflict($exception);
            $this->assertNotNull($conflict);
            $this->assertSame('VERSION_LOCKED', $conflict->errorCode);
            $this->assertSame([
                'project_version_id' => [$version->id],
                'status' => ['current' => ProjectVersionStatus::READY_TO_RELEASE->value],
            ], $conflict->errors);
        }

        $this->assertNull($link->fresh()->project_version_id);
    }

    public function test_snapshot_insert_requires_matching_released_version_metadata(): void
    {
        $manager = User::factory()->internal()->create();
        $version = ProjectVersion::factory()->create([
            'release_notes' => 'Original',
        ]);

        try {
            DB::table('project_version_release_snapshots')->insert([
                'project_version_id' => $version->id,
                'requirement_scope' => '[]',
                'task_count' => 0,
                'defect_count' => 0,
                'gate_result' => '{}',
                'release_notes' => 'Original',
                'is_override' => false,
                'override_reason' => null,
                'released_by_id' => $manager->id,
                'released_at' => now(),
            ]);
            $this->fail('Expected a draft version snapshot to be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame('IV003', $exception->getCode());
        }

        $this->assertDatabaseMissing('project_version_release_snapshots', [
            'project_version_id' => $version->id,
        ]);
    }

    public function test_snapshot_database_trigger_rolls_back_and_reapplies_cleanly(): void
    {
        $migration = require database_path(
            'migrations/2026_08_25_000031_serialize_release_gate_mutations.php',
        );
        $manager = User::factory()->internal()->create();
        $releasedAt = now();
        $version = ProjectVersion::factory()->create([
            'status' => ProjectVersionStatus::RELEASED,
            'release_notes' => 'Original',
            'released_by_id' => $manager->id,
            'released_at' => $releasedAt,
        ]);
        $snapshotId = DB::table('project_version_release_snapshots')->insertGetId([
            'project_version_id' => $version->id,
            'requirement_scope' => '[]',
            'task_count' => 0,
            'defect_count' => 0,
            'gate_result' => '{}',
            'release_notes' => 'Original',
            'is_override' => false,
            'override_reason' => null,
            'released_by_id' => $manager->id,
            'released_at' => $releasedAt,
        ]);

        try {
            $migration->down();
            $this->assertSame(1, DB::table('project_version_release_snapshots')
                ->where('id', $snapshotId)
                ->update(['release_notes' => 'Rollback allowed']));

            $migration->up();
            try {
                DB::table('project_version_release_snapshots')
                    ->where('id', $snapshotId)
                    ->delete();
                $this->fail('Expected reapplied immutable trigger to reject delete.');
            } catch (QueryException $exception) {
                $this->assertSame('IV002', $exception->getCode());
            }
        } finally {
            $triggerExists = DB::selectOne(<<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM pg_trigger
                    WHERE tgname = 'trg_project_version_release_snapshots_immutable'
                      AND NOT tgisinternal
                ) AS present
                SQL)->present;
            if (! $triggerExists) {
                $migration->up();
            }
        }
    }
}
