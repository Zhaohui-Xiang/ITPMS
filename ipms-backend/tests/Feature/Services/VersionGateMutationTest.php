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
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use App\Services\ReleaseGateService;
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
            $this->assertSame('IV004', $exception->getCode());
            $conflict = VersionGateLock::mutationConflict($exception);
            $this->assertNotNull($conflict);
            $this->assertSame('VERSION_SCOPE_BUSY', $conflict->errorCode);
            $this->assertSame(
                'The requirement scope changed outside its controlled workflow. Reload and try again.',
                $conflict->getMessage(),
            );
            $this->assertSame(
                ['requirement_project' => ['reload_required']],
                $conflict->errors,
            );
        }

        $this->assertNull($link->fresh()->project_version_id);
    }

    public function test_requirement_project_insert_requires_workflow_context(): void
    {
        $requirement = Requirement::factory()->create();
        $project = Project::factory()->create();

        try {
            DB::table('requirement_project')->insert([
                'requirement_id' => $requirement->id,
                'project_id' => $project->id,
                'project_version_id' => null,
                'delivery_status' => ProjectDeliveryStatus::ACCEPTED->value,
                'version_assigned_by_id' => null,
                'version_assigned_at' => null,
                'created_at' => now(),
            ]);
            $this->fail('Expected a direct pivot insert to require workflow context.');
        } catch (QueryException $exception) {
            $this->assertSame('IV004', $exception->getCode());
        }

        $this->assertDatabaseMissing('requirement_project', [
            'requirement_id' => $requirement->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_deleting_an_assigner_preserves_version_assignment_with_null_actor(): void
    {
        $assigner = User::factory()->internal()->create();
        $link = RequirementProject::factory()->create([
            'version_assigned_by_id' => $assigner->id,
            'version_assigned_at' => now(),
        ]);

        $assigner->delete();

        $this->assertNull($link->fresh()->version_assigned_by_id);
    }

    public function test_deleting_a_requirement_cascades_its_project_links(): void
    {
        $link = RequirementProject::factory()->create();
        $requirement = $link->requirement;

        $requirement->delete();

        $this->assertDatabaseMissing('requirement_project', ['id' => $link->id]);
    }

    public function test_release_and_acceptance_contexts_cannot_change_pivot_identity(): void
    {
        $version = ProjectVersion::factory()->ready()->withPassingScope()->create();
        $link = $version->requirementLinks()->sole();
        $newId = $link->id + 100000;

        try {
            DB::transaction(function () use ($version, $link, $newId): void {
                DB::select(
                    "SELECT set_config('itpms.requirement_project_write_ids', ?, true)",
                    [json_encode([$link->id], JSON_THROW_ON_ERROR)],
                );
                DB::select(
                    "SELECT set_config('itpms.release_project_version_id', ?, true)",
                    [(string) $version->id],
                );
                DB::table('requirement_project')->where('id', $link->id)->update([
                    'id' => $newId,
                    'delivery_status' => ProjectDeliveryStatus::DEPLOYED->value,
                ]);
                $this->fail('Expected release context to preserve pivot identity.');
            });
        } catch (QueryException $exception) {
            $this->assertSame('IV001', $exception->getCode());
        }

        DB::transaction(function () use ($version, $link): void {
            DB::select(
                "SELECT set_config('itpms.requirement_project_write_ids', ?, true)",
                [json_encode([$link->id], JSON_THROW_ON_ERROR)],
            );
            DB::select(
                "SELECT set_config('itpms.release_project_version_id', ?, true)",
                [(string) $version->id],
            );
            DB::table('requirement_project')->where('id', $link->id)->update([
                'delivery_status' => ProjectDeliveryStatus::DEPLOYED->value,
            ]);
            DB::table('project_versions')->where('id', $version->id)->update([
                'status' => ProjectVersionStatus::RELEASED->value,
            ]);
        });

        try {
            DB::transaction(function () use ($version, $link, $newId): void {
                DB::select(
                    "SELECT set_config('itpms.requirement_project_write_ids', ?, true)",
                    [json_encode([$link->id], JSON_THROW_ON_ERROR)],
                );
                DB::select(
                    "SELECT set_config('itpms.acceptance_project_version_id', ?, true)",
                    [(string) $version->id],
                );
                DB::table('requirement_project')->where('id', $link->id)->update([
                    'id' => $newId,
                    'delivery_status' => ProjectDeliveryStatus::ACCEPTED->value,
                ]);
                $this->fail('Expected acceptance context to preserve pivot identity.');
            });
        } catch (QueryException $exception) {
            $this->assertSame('IV001', $exception->getCode());
        }
    }

    public function test_snapshot_insert_requires_release_service_context_and_exact_live_payload(): void
    {
        $manager = User::factory()->internal()->create();
        $releasedAt = now();
        $version = ProjectVersion::factory()->create([
            'status' => ProjectVersionStatus::READY_TO_RELEASE,
            'release_notes' => 'Original',
        ]);
        $expectedGate = [
            ...app(ReleaseGateService::class)
                ->check($version, ProjectVersionStatus::RELEASED)
                ->jsonSerialize(),
            'original_status' => ProjectVersionStatus::READY_TO_RELEASE->value,
        ];
        $forgedGate = $expectedGate;
        $forgedGate['checks'][0]['details']['missing'] = ['forged'];

        try {
            DB::transaction(function () use (
                $version,
                $manager,
                $releasedAt,
                $forgedGate,
            ): void {
                DB::select(
                    "SELECT set_config('itpms.release_snapshot_version_id', ?, true)",
                    [(string) $version->id],
                );
                DB::table('project_version_release_snapshots')->insert([
                    'project_version_id' => $version->id,
                    'requirement_scope' => '[]',
                    'task_count' => 0,
                    'defect_count' => 0,
                    'gate_result' => json_encode($forgedGate, JSON_THROW_ON_ERROR),
                    'release_notes' => 'Original',
                    'is_override' => false,
                    'override_reason' => null,
                    'released_by_id' => $manager->id,
                    'released_at' => $releasedAt,
                ]);
            });
            $this->fail('Expected a forged snapshot outside the release service to be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame('IV003', $exception->getCode());
        }

        $this->assertDatabaseMissing('project_version_release_snapshots', [
            'project_version_id' => $version->id,
        ]);
    }

    public function test_snapshot_preflight_rejects_coordinated_duplicate_scope_forgery(): void
    {
        $migration = require database_path(
            'migrations/2026_08_25_000031_serialize_release_gate_mutations.php',
        );
        $manager = User::factory()->internal()->create();
        $version = ProjectVersion::factory()->inTesting()->create([
            'release_notes' => 'Original',
        ]);
        $links = collect([
            RequirementProject::factory()->forVersion($version)->create([
                'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY,
            ]),
            RequirementProject::factory()->forVersion($version)->create([
                'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY,
            ]),
        ])->sortBy('id')->values();
        DB::table('project_versions')->where('id', $version->id)->update([
            'status' => ProjectVersionStatus::READY_TO_RELEASE->value,
        ]);
        $releasedAt = now();
        $gateResult = [
            ...app(ReleaseGateService::class)
                ->check($version, ProjectVersionStatus::RELEASED)
                ->jsonSerialize(),
            'original_status' => ProjectVersionStatus::READY_TO_RELEASE->value,
        ];
        $scope = $links->map(static fn (RequirementProject $link): array => [
            'requirement_project_id' => $link->id,
            'requirement_id' => $link->requirement_id,
            'project_id' => $link->project_id,
            'pre_release_delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY->value,
            'delivery_status' => ProjectDeliveryStatus::DEPLOYED->value,
        ])->all();

        $snapshotId = DB::transaction(function () use (
            $version,
            $manager,
            $links,
            $scope,
            $gateResult,
            $releasedAt,
        ): int {
            DB::select(
                "SELECT set_config('itpms.release_snapshot_version_id', ?, true)",
                [(string) $version->id],
            );
            $snapshotId = DB::table('project_version_release_snapshots')->insertGetId([
                'project_version_id' => $version->id,
                'requirement_scope' => json_encode($scope, JSON_THROW_ON_ERROR),
                'task_count' => 0,
                'defect_count' => 0,
                'gate_result' => json_encode($gateResult, JSON_THROW_ON_ERROR),
                'release_notes' => 'Original',
                'is_override' => false,
                'override_reason' => null,
                'released_by_id' => $manager->id,
                'released_at' => $releasedAt,
            ]);
            DB::table('project_version_histories')->insert([
                'project_version_id' => $version->id,
                'event_type' => 'release',
                'from_status' => ProjectVersionStatus::READY_TO_RELEASE->value,
                'to_status' => ProjectVersionStatus::RELEASED->value,
                'actor_id' => $manager->id,
                'reason' => null,
                'metadata' => json_encode([
                    'is_override' => false,
                    'snapshot_id' => $snapshotId,
                    'requirement_project_ids' => $links->pluck('id')->all(),
                    'failed_gates' => [],
                    'snapshot_payload' => [
                        'requirement_scope' => $scope,
                        'task_count' => 0,
                        'defect_count' => 0,
                        'gate_result' => $gateResult,
                        'release_notes' => 'Original',
                    ],
                ], JSON_THROW_ON_ERROR),
                'created_at' => $releasedAt,
            ]);
            DB::select(
                "SELECT set_config('itpms.requirement_project_write_ids', ?, true)",
                [json_encode($links->pluck('id')->all(), JSON_THROW_ON_ERROR)],
            );
            DB::select(
                "SELECT set_config('itpms.release_project_version_id', ?, true)",
                [(string) $version->id],
            );
            DB::table('requirement_project')->whereIn('id', $links->pluck('id'))
                ->update(['delivery_status' => ProjectDeliveryStatus::DEPLOYED->value]);
            DB::table('requirements')->whereIn('id', $links->pluck('requirement_id'))
                ->update(['status' => ProjectDeliveryStatus::DEPLOYED->value]);
            DB::table('project_versions')->where('id', $version->id)->update([
                'status' => ProjectVersionStatus::RELEASED->value,
                'released_by_id' => $manager->id,
                'released_at' => $releasedAt,
            ]);

            return $snapshotId;
        });

        $forgedScope = [$scope[0], $scope[0]];
        $forgedGate = $gateResult;
        foreach ($forgedGate['checks'] as &$check) {
            if ($check['code'] === 'non_empty_scope') {
                $check['details']['requirement_project_ids'] = [
                    $links[0]->id,
                    $links[0]->id,
                ];
            }
        }
        unset($check);

        $migration->down();
        DB::table('project_version_release_snapshots')->where('id', $snapshotId)->update([
            'requirement_scope' => json_encode($forgedScope, JSON_THROW_ON_ERROR),
            'gate_result' => json_encode($forgedGate, JSON_THROW_ON_ERROR),
        ]);

        $accepted = false;
        $sqlState = null;
        try {
            $migration->up();
            $accepted = true;
        } catch (QueryException $exception) {
            $sqlState = $exception->getCode();
        }

        if ($accepted) {
            $migration->down();
        }
        DB::table('project_version_release_snapshots')->where('id', $snapshotId)->update([
            'requirement_scope' => json_encode($scope, JSON_THROW_ON_ERROR),
            'gate_result' => json_encode($gateResult, JSON_THROW_ON_ERROR),
        ]);
        $migration->up();

        $this->assertFalse($accepted, 'Expected preflight to reject duplicate forged scope.');
        $this->assertSame('IV003', $sqlState);

        $migration->down();
        DB::table('project_versions')->where('id', $version->id)->update([
            'release_notes' => 'Coordinated forged notes',
        ]);
        DB::table('project_version_release_snapshots')->where('id', $snapshotId)->update([
            'release_notes' => 'Coordinated forged notes',
        ]);

        $accepted = false;
        $sqlState = null;
        try {
            $migration->up();
            $accepted = true;
        } catch (QueryException $exception) {
            $sqlState = $exception->getCode();
        }

        if ($accepted) {
            $migration->down();
        }
        DB::table('project_versions')->where('id', $version->id)->update([
            'release_notes' => 'Original',
        ]);
        DB::table('project_version_release_snapshots')->where('id', $snapshotId)->update([
            'release_notes' => 'Original',
        ]);
        $migration->up();

        $this->assertFalse($accepted, 'Expected preflight to reject forged snapshot payload.');
        $this->assertSame('IV003', $sqlState);
    }

    public function test_snapshot_database_trigger_rolls_back_and_reapplies_cleanly(): void
    {
        $migration = require database_path(
            'migrations/2026_08_25_000031_serialize_release_gate_mutations.php',
        );
        $manager = User::factory()->internal()->create();
        $releasedAt = now();
        $version = ProjectVersion::factory()->create([
            'status' => ProjectVersionStatus::READY_TO_RELEASE,
            'release_notes' => 'Original',
        ]);
        $gateResult = [
            ...app(ReleaseGateService::class)
                ->check($version, ProjectVersionStatus::RELEASED)
                ->jsonSerialize(),
            'original_status' => ProjectVersionStatus::READY_TO_RELEASE->value,
        ];
        $snapshotId = DB::transaction(function () use (
            $version,
            $manager,
            $releasedAt,
            $gateResult,
        ): int {
            DB::select(
                "SELECT set_config('itpms.release_snapshot_version_id', ?, true)",
                [(string) $version->id],
            );
            $snapshotId = DB::table('project_version_release_snapshots')->insertGetId([
                'project_version_id' => $version->id,
                'requirement_scope' => '[]',
                'task_count' => 0,
                'defect_count' => 0,
                'gate_result' => json_encode($gateResult, JSON_THROW_ON_ERROR),
                'release_notes' => 'Original',
                'is_override' => false,
                'override_reason' => null,
                'released_by_id' => $manager->id,
                'released_at' => $releasedAt,
            ]);
            DB::table('project_version_histories')->insert([
                'project_version_id' => $version->id,
                'event_type' => 'release',
                'from_status' => ProjectVersionStatus::READY_TO_RELEASE->value,
                'to_status' => ProjectVersionStatus::RELEASED->value,
                'actor_id' => $manager->id,
                'reason' => null,
                'metadata' => json_encode([
                    'is_override' => false,
                    'snapshot_id' => $snapshotId,
                    'requirement_project_ids' => [],
                    'failed_gates' => [],
                    'snapshot_payload' => [
                        'requirement_scope' => [],
                        'task_count' => 0,
                        'defect_count' => 0,
                        'gate_result' => $gateResult,
                        'release_notes' => 'Original',
                    ],
                ], JSON_THROW_ON_ERROR),
                'created_at' => $releasedAt,
            ]);
            DB::table('project_versions')->where('id', $version->id)->update([
                'status' => ProjectVersionStatus::RELEASED->value,
                'released_by_id' => $manager->id,
                'released_at' => $releasedAt,
            ]);

            return $snapshotId;
        });
        $forgedGate = $gateResult;
        $forgedGate['checks'][0]['details']['missing'] = ['forged'];

        try {
            $migration->down();
            $this->assertSame(1, DB::table('project_version_release_snapshots')
                ->where('id', $snapshotId)
                ->update([
                    'gate_result' => json_encode($forgedGate, JSON_THROW_ON_ERROR),
                ]));

            try {
                $migration->up();
                $this->fail('Expected reapply to reject the invalid stored snapshot.');
            } catch (QueryException $exception) {
                $this->assertSame('IV003', $exception->getCode());
            }

            DB::table('project_version_release_snapshots')
                ->where('id', $snapshotId)
                ->update([
                    'gate_result' => json_encode($gateResult, JSON_THROW_ON_ERROR),
                ]);
            $migration->up();

            $snapshotRow = (array) DB::table('project_version_release_snapshots')
                ->where('id', $snapshotId)
                ->firstOrFail();
            $migration->down();
            DB::table('project_version_release_snapshots')
                ->where('id', $snapshotId)
                ->delete();

            $acceptedMissingSnapshot = false;
            try {
                $migration->up();
                $acceptedMissingSnapshot = true;
            } catch (QueryException $exception) {
                $this->assertSame('IV003', $exception->getCode());
            }

            if ($acceptedMissingSnapshot) {
                $migration->down();
            }
            DB::table('project_version_release_snapshots')->insert($snapshotRow);
            $migration->up();

            $this->assertFalse(
                $acceptedMissingSnapshot,
                'Expected reapply to reject a released version with no snapshot.',
            );

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
