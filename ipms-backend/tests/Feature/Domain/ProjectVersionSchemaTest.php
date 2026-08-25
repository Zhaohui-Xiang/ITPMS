<?php

namespace Tests\Feature\Domain;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\TaskStatus;
use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\ProjectVersionHistory;
use App\Models\ProjectVersionReleaseSnapshot;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectVersionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_version_statuses_and_transitions_are_exact(): void
    {
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7],
            array_column(ProjectVersionStatus::cases(), 'value'),
        );

        $this->assertSame([
            ProjectVersionStatus::DRAFT->value => [ProjectVersionStatus::PLANNED],
            ProjectVersionStatus::PLANNED->value => [ProjectVersionStatus::DRAFT, ProjectVersionStatus::IN_DEVELOPMENT],
            ProjectVersionStatus::IN_DEVELOPMENT->value => [ProjectVersionStatus::PLANNED, ProjectVersionStatus::IN_TESTING],
            ProjectVersionStatus::IN_TESTING->value => [ProjectVersionStatus::IN_DEVELOPMENT, ProjectVersionStatus::READY_TO_RELEASE],
            ProjectVersionStatus::READY_TO_RELEASE->value => [ProjectVersionStatus::IN_TESTING, ProjectVersionStatus::RELEASED],
            ProjectVersionStatus::RELEASED->value => [ProjectVersionStatus::ARCHIVED],
            ProjectVersionStatus::ARCHIVED->value => [],
        ], collect(ProjectVersionStatus::cases())->mapWithKeys(
            fn (ProjectVersionStatus $status): array => [$status->value => $status->allowedTransitions()],
        )->all());

        $this->assertSame([
            ProjectVersionStatus::DRAFT->value => '草稿',
            ProjectVersionStatus::PLANNED->value => '已计划',
            ProjectVersionStatus::IN_DEVELOPMENT->value => '开发中',
            ProjectVersionStatus::IN_TESTING->value => '测试中',
            ProjectVersionStatus::READY_TO_RELEASE->value => '待发布',
            ProjectVersionStatus::RELEASED->value => '已发布',
            ProjectVersionStatus::ARCHIVED->value => '已归档',
        ], collect(ProjectVersionStatus::cases())->mapWithKeys(fn (ProjectVersionStatus $status): array => [$status->value => $status->label()])->all());
    }

    public function test_project_delivery_statuses_only_move_one_step_forward(): void
    {
        $this->assertSame(
            [2, 3, 4, 5, 6, 7],
            array_column(ProjectDeliveryStatus::cases(), 'value'),
        );

        $this->assertSame([
            ProjectDeliveryStatus::ASSIGNED->value => [ProjectDeliveryStatus::IN_DEVELOPMENT],
            ProjectDeliveryStatus::IN_DEVELOPMENT->value => [ProjectDeliveryStatus::IN_TESTING],
            ProjectDeliveryStatus::IN_TESTING->value => [ProjectDeliveryStatus::PENDING_DEPLOY],
            ProjectDeliveryStatus::PENDING_DEPLOY->value => [ProjectDeliveryStatus::DEPLOYED],
            ProjectDeliveryStatus::DEPLOYED->value => [ProjectDeliveryStatus::ACCEPTED],
            ProjectDeliveryStatus::ACCEPTED->value => [],
        ], collect(ProjectDeliveryStatus::cases())->mapWithKeys(
            fn (ProjectDeliveryStatus $status): array => [$status->value => $status->allowedForwardTransitions()],
        )->all());

        $this->assertSame([
            ProjectDeliveryStatus::ASSIGNED->value => '已分配',
            ProjectDeliveryStatus::IN_DEVELOPMENT->value => '开发中',
            ProjectDeliveryStatus::IN_TESTING->value => '测试中',
            ProjectDeliveryStatus::PENDING_DEPLOY->value => '待上线',
            ProjectDeliveryStatus::DEPLOYED->value => '已上线',
            ProjectDeliveryStatus::ACCEPTED->value => '已验收',
        ], collect(ProjectDeliveryStatus::cases())->mapWithKeys(fn (ProjectDeliveryStatus $status): array => [$status->value => $status->label()])->all());
    }

    public function test_project_version_code_is_unique_within_project_only(): void
    {
        $first = Project::factory()->create();
        $second = Project::factory()->create();

        ProjectVersion::factory()->for($first)->create(['code' => 'v2.3']);
        ProjectVersion::factory()->for($second)->create(['code' => 'v2.3']);

        $this->expectException(QueryException::class);
        ProjectVersion::factory()->for($first)->create(['code' => 'v2.3']);
    }

    public function test_requirement_project_has_one_optional_target_version(): void
    {
        $version = ProjectVersion::factory()->create();
        $requirement = Requirement::factory()->create();
        $link = RequirementProject::create([
            'requirement_id' => $requirement->id,
            'project_id' => $version->project_id,
            'project_version_id' => $version->id,
            'delivery_status' => ProjectDeliveryStatus::ASSIGNED,
        ]);

        $this->assertTrue($link->projectVersion->is($version));
        $this->assertSame(ProjectDeliveryStatus::ASSIGNED, $link->delivery_status);

        $unassigned = RequirementProject::factory()->create([
            'project_version_id' => null,
        ]);

        $this->assertNull($unassigned->projectVersion);
    }

    public function test_target_version_must_belong_to_the_link_project(): void
    {
        $version = ProjectVersion::factory()->create();
        $otherProject = Project::factory()->create();

        $this->expectException(QueryException::class);
        RequirementProject::factory()->create([
            'project_id' => $otherProject->id,
            'project_version_id' => $version->id,
        ]);
    }

    public function test_project_version_rejects_an_unknown_status(): void
    {
        $version = ProjectVersion::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('project_versions')
            ->where('id', $version->id)
            ->update(['status' => 8]);
    }

    public function test_project_version_rejects_a_lock_version_below_one(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $version = ProjectVersion::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('project_versions')
            ->where('id', $version->id)
            ->update(['lock_version' => 0]);
    }

    public function test_requirement_project_rejects_an_unknown_delivery_status(): void
    {
        $link = RequirementProject::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('requirement_project')
            ->where('id', $link->id)
            ->update(['delivery_status' => 1]);
    }

    public function test_history_rejects_an_unknown_from_status(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $version = ProjectVersion::factory()->create();
        $actor = User::factory()->internal()->create();

        $this->expectException(QueryException::class);
        DB::table('project_version_histories')->insert([
            'project_version_id' => $version->id,
            'event_type' => 'invalid_from_status',
            'from_status' => 8,
            'to_status' => ProjectVersionStatus::PLANNED->value,
            'actor_id' => $actor->id,
            'metadata' => '{}',
            'created_at' => now(),
        ]);
    }

    public function test_history_rejects_an_unknown_to_status(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $version = ProjectVersion::factory()->create();
        $actor = User::factory()->internal()->create();

        $this->expectException(QueryException::class);
        DB::table('project_version_histories')->insert([
            'project_version_id' => $version->id,
            'event_type' => 'invalid_to_status',
            'from_status' => ProjectVersionStatus::DRAFT->value,
            'to_status' => 8,
            'actor_id' => $actor->id,
            'metadata' => '{}',
            'created_at' => now(),
        ]);
    }

    public function test_release_snapshot_rejects_override_reason_without_override(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $version = ProjectVersion::factory()->create();
        $actor = User::factory()->internal()->create();

        $this->expectException(QueryException::class);
        DB::table('project_version_release_snapshots')->insert([
            'project_version_id' => $version->id,
            'requirement_scope' => '[]',
            'task_count' => 0,
            'defect_count' => 0,
            'gate_result' => '{}',
            'release_notes' => null,
            'is_override' => false,
            'override_reason' => 'not allowed',
            'released_by_id' => $actor->id,
            'released_at' => now(),
        ]);
    }

    public function test_deleting_a_version_cascades_its_history(): void
    {
        $version = ProjectVersion::factory()->create();
        $actor = User::factory()->internal()->create();
        $history = ProjectVersionHistory::create([
            'project_version_id' => $version->id,
            'event_type' => 'created',
            'actor_id' => $actor->id,
            'metadata' => [],
            'created_at' => now(),
        ]);

        $version->delete();

        $this->assertDatabaseMissing('project_version_histories', ['id' => $history->id]);
    }

    public function test_requirement_assignment_restricts_target_version_deletion(): void
    {
        $version = ProjectVersion::factory()->create();
        RequirementProject::factory()->forVersion($version)->create();

        $this->expectException(QueryException::class);
        $version->delete();
    }

    public function test_release_snapshot_restricts_target_version_deletion(): void
    {
        $version = ProjectVersion::factory()->create();
        $actor = User::factory()->internal()->create();
        ProjectVersionReleaseSnapshot::create([
            'project_version_id' => $version->id,
            'requirement_scope' => [],
            'task_count' => 0,
            'defect_count' => 0,
            'gate_result' => ['passed' => true],
            'is_override' => false,
            'released_by_id' => $actor->id,
            'released_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $version->delete();
    }

    public function test_release_snapshot_is_unique_per_project_version(): void
    {
        $version = ProjectVersion::factory()->create();
        $actor = User::factory()->internal()->create();
        $attributes = [
            'project_version_id' => $version->id,
            'requirement_scope' => [],
            'task_count' => 0,
            'defect_count' => 0,
            'gate_result' => ['passed' => true],
            'release_notes' => null,
            'is_override' => false,
            'released_by_id' => $actor->id,
            'released_at' => now(),
        ];

        ProjectVersionReleaseSnapshot::create($attributes);

        $this->expectException(QueryException::class);
        ProjectVersionReleaseSnapshot::create($attributes);
    }

    public function test_project_and_requirement_relations_expose_delivery_pivot_data(): void
    {
        $version = ProjectVersion::factory()->create();
        $requirement = Requirement::factory()->create();
        $actor = User::factory()->internal()->create();
        $assignedAt = now()->startOfSecond();

        RequirementProject::factory()->forVersion($version)->for($requirement)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_TESTING,
            'version_assigned_by_id' => $actor->id,
            'version_assigned_at' => $assignedAt,
        ]);

        $projectRequirement = $version->project->requirements()->firstOrFail();
        $requirementProject = $requirement->projects()->firstOrFail();

        foreach ([$projectRequirement->pivot, $requirementProject->pivot] as $pivot) {
            $this->assertInstanceOf(RequirementProject::class, $pivot);
            $this->assertSame($version->id, $pivot->project_version_id);
            $this->assertSame(ProjectDeliveryStatus::IN_TESTING, $pivot->delivery_status);
            $this->assertTrue($pivot->version_assigned_at->equalTo($assignedAt));
        }

        $this->assertTrue($version->project->versions->contains($version));
        $this->assertTrue($requirement->projectLinks->firstOrFail()->projectVersion->is($version));
        $this->assertTrue($version->requirements->contains($requirement));
    }

    public function test_history_and_release_snapshot_cast_structured_fields(): void
    {
        $version = ProjectVersion::factory()->create();
        $actor = User::factory()->internal()->create();

        $history = ProjectVersionHistory::create([
            'project_version_id' => $version->id,
            'event_type' => 'status_forward',
            'from_status' => ProjectVersionStatus::DRAFT,
            'to_status' => ProjectVersionStatus::PLANNED,
            'actor_id' => $actor->id,
            'metadata' => ['source' => 'test'],
            'created_at' => now(),
        ]);
        $snapshot = ProjectVersionReleaseSnapshot::create([
            'project_version_id' => $version->id,
            'requirement_scope' => [['requirement_id' => 1]],
            'task_count' => 1,
            'defect_count' => 0,
            'gate_result' => ['passed' => true],
            'release_notes' => 'Schema test',
            'is_override' => false,
            'released_by_id' => $actor->id,
            'released_at' => now(),
        ]);

        $this->assertSame(ProjectVersionStatus::DRAFT, $history->from_status);
        $this->assertSame(ProjectVersionStatus::PLANNED, $history->to_status);
        $this->assertSame(['source' => 'test'], $history->metadata);
        $this->assertSame([['requirement_id' => 1]], $snapshot->requirement_scope);
        $this->assertSame(['passed' => true], $snapshot->gate_result);
        $this->assertTrue($version->histories->contains($history));
        $this->assertTrue($version->releaseSnapshot->is($snapshot));
    }

    public function test_default_task_and_defect_factories_create_their_scope_links(): void
    {
        $task = Task::factory()->create();
        $defect = Defect::factory()->create();

        $this->assertDatabaseHas('requirement_project', [
            'requirement_id' => $task->requirement_id,
            'project_id' => $task->project_id,
        ]);
        $this->assertDatabaseHas('requirement_project', [
            'requirement_id' => $defect->requirement_id,
            'project_id' => $defect->project_id,
        ]);
    }

    public function test_work_item_factories_reuse_existing_scope_without_overwriting_version_assignment(): void
    {
        $version = ProjectVersion::factory()->create();
        $actor = User::factory()->internal()->create();
        $link = RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_TESTING,
            'version_assigned_by_id' => $actor->id,
            'version_assigned_at' => now()->startOfSecond(),
        ]);

        Task::factory()->create([
            'requirement_id' => $link->requirement_id,
            'project_id' => $link->project_id,
        ]);
        Defect::factory()->create([
            'requirement_id' => $link->requirement_id,
            'project_id' => $link->project_id,
        ]);

        $link->refresh();

        $this->assertSame(1, RequirementProject::query()
            ->where('requirement_id', $link->requirement_id)
            ->where('project_id', $link->project_id)
            ->count());
        $this->assertSame($version->id, $link->project_version_id);
        $this->assertSame(ProjectDeliveryStatus::IN_TESTING, $link->delivery_status);
        $this->assertSame($actor->id, $link->version_assigned_by_id);
    }

    public function test_version_scope_factories_remain_bound_to_the_target_version(): void
    {
        $version = ProjectVersion::factory()->create();
        $link = RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY,
        ]);

        $task = Task::factory()->forVersionScope($version)->create();
        $defect = Defect::factory()->forVersionScope($version)->create();

        $this->assertSame($link->requirement_id, $task->requirement_id);
        $this->assertSame($link->project_id, $task->project_id);
        $this->assertSame($link->requirement_id, $defect->requirement_id);
        $this->assertSame($link->project_id, $defect->project_id);

        $link->refresh();
        $this->assertSame($version->id, $link->project_version_id);
        $this->assertSame(ProjectDeliveryStatus::PENDING_DEPLOY, $link->delivery_status);
        $this->assertSame(1, RequirementProject::query()
            ->where('requirement_id', $link->requirement_id)
            ->where('project_id', $link->project_id)
            ->count());
    }

    public function test_factory_helpers_build_a_passing_version_scope(): void
    {
        $version = ProjectVersion::factory()->inTesting()->withPassingScope()->create();
        $link = $version->requirementLinks()->firstOrFail();

        $this->assertSame(ProjectVersionStatus::IN_TESTING, $version->status);
        $this->assertSame(ProjectDeliveryStatus::PENDING_DEPLOY, $link->delivery_status);
        $this->assertDatabaseHas('tasks', [
            'requirement_id' => $link->requirement_id,
            'project_id' => $version->project_id,
            'status' => TaskStatus::COMPLETED->value,
        ]);
        $this->assertDatabaseHas('defects', [
            'requirement_id' => $link->requirement_id,
            'project_id' => $version->project_id,
            'severity' => DefectSeverity::SERIOUS->value,
            'status' => DefectStatus::CLOSED->value,
        ]);

        Task::factory()->forVersionScope($version)->create();
        Defect::factory()->forVersionScope($version)->create();

        $this->assertSame(2, Task::query()->where('project_id', $version->project_id)->count());
        $this->assertSame(2, Defect::query()->where('project_id', $version->project_id)->count());

        $requirement = Requirement::factory()->withProjects(2)->create();
        $this->assertCount(2, $requirement->projectLinks);
        $this->assertCount(2, $requirement->projectLinks->pluck('project_id')->unique());

        $ready = ProjectVersion::factory()->ready()->create();
        $this->assertSame(ProjectVersionStatus::READY_TO_RELEASE, $ready->status);
        $this->assertNotEmpty($ready->release_notes);

        $manager = User::factory()->internal()->create();
        $managedProject = Project::factory()->withManager($manager)->create();
        $this->assertTrue($managedProject->manager->is($manager));
        $this->assertSame($manager->id, $managedProject->created_by_id);
    }
}
