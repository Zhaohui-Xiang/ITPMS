<?php

namespace Tests\Feature\Services;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\RequirementStatus;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\ProjectVersionHistory;
use App\Models\ProjectVersionReleaseSnapshot;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use App\Services\VersionGateLock;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VersionGateConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_ready_gate_and_todo_insert_serialize_to_one_consistent_result(): void
    {
        $actor = User::factory()->internal()->create();
        $version = ProjectVersion::factory()->inTesting()->withPassingScope()->create([
            'release_notes' => 'Ready',
        ]);

        [$gate, $mutation] = $this->race(
            $version->id,
            $this->transitionScript($version->id, $actor->id),
            $this->taskInsertScript($version->id),
        );

        $version->refresh();
        if ($gate['result'] === 'ready') {
            $this->assertSame('VERSION_LOCKED', $mutation['result']);
            $this->assertSame(ProjectVersionStatus::READY_TO_RELEASE, $version->status);
            $this->assertSame(0, Task::query()
                ->where('project_id', $version->project_id)
                ->where('status', TaskStatus::TODO)
                ->count());
        } else {
            $this->assertSame('RELEASE_GATE_FAILED', $gate['result']);
            $this->assertSame('mutated', $mutation['result']);
            $this->assertSame(ProjectVersionStatus::IN_TESTING, $version->status);
        }
        $this->assertNoDeadlock($gate, $mutation);
    }

    public function test_release_and_severe_defect_reopen_cannot_leave_a_released_blocker(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()->for($project)->ready()->withPassingScope()->create();
        $defect = $version->requirementLinks()->firstOrFail()->requirement
            ->defects()->where('project_id', $project->id)->firstOrFail();

        [$release, $mutation] = $this->race(
            $version->id,
            $this->releaseScript($version->id, $manager->id, false),
            $this->defectReopenScript($defect->id),
        );

        $version->refresh();
        if ($release['result'] === 'released') {
            $this->assertSame('VERSION_LOCKED', $mutation['result']);
            $this->assertSame(DefectStatus::CLOSED->value, $defect->fresh()->status);
        } else {
            $this->assertSame('RELEASE_GATE_FAILED', $release['result']);
            $this->assertSame('mutated', $mutation['result']);
            $this->assertSame(ProjectVersionStatus::READY_TO_RELEASE, $version->status);
            $this->assertSame(DefectStatus::REOPENED->value, $defect->fresh()->status);
        }
        $this->assertFalse(
            $version->status === ProjectVersionStatus::RELEASED
            && $defect->fresh()->status === DefectStatus::REOPENED->value,
        );
        $this->assertNoDeadlock($release, $mutation);
    }

    public function test_move_task_and_ready_gate_serialize_on_stable_requirement_project_scope(): void
    {
        $actor = User::factory()->internal()->create();
        $project = Project::factory()->create();
        $source = ProjectVersion::factory()->for($project)->create();
        $target = ProjectVersion::factory()->for($project)->inTesting()->withPassingScope()->create([
            'release_notes' => 'Ready',
        ]);
        $link = RequirementProject::factory()->forVersion($source)->create([
            'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY,
        ]);

        [$planning, $mutation, $gate] = $this->stableScopeRace(
            $link,
            $source,
            $target,
            $this->taskInsertForLinkScript($link->id),
            $this->transitionAfterPlanningScript($target->id, $actor->id),
            $actor,
        );

        $target->refresh();
        if ($gate['result'] === 'ready') {
            $this->assertSame('VERSION_LOCKED', $mutation['result']);
            $this->assertSame(0, Task::query()
                ->where('requirement_id', $link->requirement_id)
                ->where('project_id', $project->id)
                ->where('status', TaskStatus::TODO)
                ->count());
        } else {
            $this->assertSame('RELEASE_GATE_FAILED', $gate['result']);
            $this->assertSame('mutated', $mutation['result']);
            $this->assertSame(ProjectVersionStatus::IN_TESTING, $target->status);
        }

        $this->assertSame('planned', $planning['result']);
        $this->assertFalse(
            $target->status === ProjectVersionStatus::READY_TO_RELEASE
            && Task::query()
                ->where('requirement_id', $link->requirement_id)
                ->where('project_id', $project->id)
                ->where('status', TaskStatus::TODO)
                ->exists(),
        );
        $this->assertNoDeadlock($planning, $mutation, $gate);
    }

    public function test_plan_defect_and_release_serialize_on_stable_requirement_project_scope(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->withManager($manager)->create();
        $target = ProjectVersion::factory()->for($project)->inTesting()->withPassingScope()->create([
            'release_notes' => 'Ready',
        ]);
        $link = $target->requirementLinks()->firstOrFail();
        DB::table('requirement_project')->where('id', $link->id)->update([
            'project_version_id' => null,
            'version_assigned_by_id' => null,
            'version_assigned_at' => null,
        ]);
        $link->refresh();

        [$planning, $mutation, $gate] = $this->stableScopeRace(
            $link,
            null,
            $target,
            $this->defectInsertForLinkScript($link->id),
            $this->releaseAfterPlanningScript($target->id, $manager->id),
            $manager,
        );

        $target->refresh();
        if ($gate['result'] === 'released') {
            $this->assertSame('VERSION_LOCKED', $mutation['result']);
            $this->assertSame(0, Defect::query()
                ->where('requirement_id', $link->requirement_id)
                ->where('project_id', $project->id)
                ->where('severity', DefectSeverity::SERIOUS)
                ->where('status', '!=', DefectStatus::CLOSED)
                ->count());
        } else {
            $this->assertSame('RELEASE_GATE_FAILED', $gate['result']);
            $this->assertSame('mutated', $mutation['result']);
            $this->assertNotSame(ProjectVersionStatus::RELEASED, $target->status);
        }

        $this->assertSame('planned', $planning['result']);
        $this->assertFalse(
            $target->status === ProjectVersionStatus::RELEASED
            && Defect::query()
                ->where('requirement_id', $link->requirement_id)
                ->where('project_id', $project->id)
                ->where('severity', DefectSeverity::SERIOUS)
                ->where('status', '!=', DefectStatus::CLOSED)
                ->exists(),
        );
        $this->assertNoDeadlock($planning, $mutation, $gate);
    }

    public function test_release_and_delivery_transition_share_lock_order_without_deadlock(): void
    {
        $admin = User::factory()->withRole('super_admin')->create();
        $actor = User::factory()->withRole('it_pm')->create();
        $version = ProjectVersion::factory()->inTesting()->create();
        DB::table('project_members')->insert([
            'project_id' => $version->project_id,
            'user_id' => $actor->id,
            'role_in_project' => 'pm',
            'assigned_at' => now(),
        ]);
        $link = RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::IN_TESTING,
        ]);

        [$release, $transition] = $this->race(
            $version->id,
            $this->releaseScript($version->id, $admin->id, true),
            $this->deliveryScript(
                $link->requirement_id,
                $version->project_id,
                $actor->id,
            ),
        );

        $this->assertSame('released', $release['result']);
        $this->assertContains($transition['result'], ['transitioned', 'VERSION_LOCKED']);
        $this->assertSame(ProjectVersionStatus::RELEASED, $version->fresh()->status);
        $this->assertSame(ProjectDeliveryStatus::DEPLOYED, $link->fresh()->delivery_status);
        $this->assertNoDeadlock($release, $transition);
    }

    public function test_release_and_resubmit_share_lock_order_without_deadlock(): void
    {
        $admin = User::factory()->withRole('super_admin')->create();
        $requester = User::factory()->systemUser()->create();
        $reviewer = User::factory()->internal()->create();
        $version = ProjectVersion::factory()->inTesting()->create();
        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'status' => RequirementStatus::PENDING_REVIEW,
            'reviewer_id' => $reviewer->id,
            'review_comment' => 'Rejected',
            'reviewed_at' => now(),
        ]);
        $link = RequirementProject::factory()
            ->for($requirement)->forVersion($version)->create([
                'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY,
            ]);

        [$release, $resubmit] = $this->race(
            $version->id,
            $this->releaseScript($version->id, $admin->id, true),
            $this->resubmitScript(
                $requirement->id,
                $requester->id,
                $version->project_id,
            ),
        );

        $this->assertSame('released', $release['result']);
        $this->assertContains($resubmit['result'], ['resubmitted', 'VERSION_LOCKED']);
        $this->assertSame(ProjectVersionStatus::RELEASED, $version->fresh()->status);
        $this->assertSame(ProjectDeliveryStatus::DEPLOYED, $link->fresh()->delivery_status);
        $this->assertNoDeadlock($release, $resubmit);
    }

    public function test_concurrent_normal_and_force_releases_write_exactly_once(): void
    {
        foreach ([false, true] as $force) {
            $actor = $force
                ? User::factory()->withRole('super_admin')->create()
                : User::factory()->withRole('it_pm')->create();
            $project = $force
                ? Project::factory()->create()
                : Project::factory()->withManager($actor)->create();
            $factory = ProjectVersion::factory()->for($project)->withPassingScope();
            $version = $force ? $factory->inTesting()->create() : $factory->ready()->create();

            [$first, $second] = $this->race(
                $version->id,
                $this->releaseScript($version->id, $actor->id, $force),
                $this->releaseScript($version->id, $actor->id, $force),
            );

            $this->assertSame('released', $first['result']);
            $this->assertSame('released', $second['result']);
            $this->assertSame(1, ProjectVersionReleaseSnapshot::query()
                ->where('project_version_id', $version->id)->count());
            $this->assertSame(1, ProjectVersionHistory::query()
                ->where('project_version_id', $version->id)->count());
            $this->assertSame(1, AuditLog::query()
                ->where('target_type', 'project_version')
                ->where('target_id', (string) $version->id)->count());
            $this->assertNoDeadlock($first, $second);
        }
    }

    public function test_direct_scope_update_and_release_return_a_retryable_conflict_without_deadlock(): void
    {
        [$release, $mapping] = $this->directMappingReleaseRace('update');

        $this->assertSame('released', $release['result']);
        $this->assertNoDeadlock($release, $mapping);
        $this->assertSame('VERSION_LOCKED', $mapping['result']);
    }

    public function test_direct_scope_delete_and_release_return_a_retryable_conflict_without_deadlock(): void
    {
        [$release, $mapping] = $this->directMappingReleaseRace('delete');

        $this->assertSame('released', $release['result']);
        $this->assertNoDeadlock($release, $mapping);
        $this->assertSame('VERSION_LOCKED', $mapping['result']);
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function directMappingReleaseRace(string $operation): array
    {
        $admin = User::factory()->withRole('super_admin')->create();
        $version = ProjectVersion::factory()->inTesting()->create();
        $link = RequirementProject::factory()->forVersion($version)->create([
            'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY,
        ]);
        $prefix = sys_get_temp_dir().'/ipms-mapping-release-race-'.bin2hex(random_bytes(8));
        $scopeLocked = $prefix.'-scope-locked';
        $mappingPid = $prefix.'-mapping-pid';
        $proceed = $prefix.'-proceed';
        $release = new Process([
            PHP_BINARY,
            '-r',
            $this->releaseHoldingScopeScript($version, $link, $admin, $scopeLocked, $proceed),
        ], base_path());
        $mapping = new Process([
            PHP_BINARY,
            '-r',
            $this->directMappingScript($link->id, $operation, $mappingPid),
        ], base_path());

        foreach ([$release, $mapping] as $process) {
            $process->setTimeout(15);
        }

        try {
            $release->start();
            $this->waitForFile($scopeLocked, $release);
            $mapping->start();
            $this->waitForFile($mappingPid, $mapping);
            $this->waitForAdvisoryWaitOrCompletion((int) file_get_contents($mappingPid), $mapping);
            touch($proceed);
            $release->wait();
            $mapping->wait();
        } finally {
            foreach ([$scopeLocked, $mappingPid, $proceed] as $path) {
                @unlink($path);
            }
            foreach ([$release, $mapping] as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }

        $this->assertTrue($release->isSuccessful(), $release->getErrorOutput());
        $this->assertTrue($mapping->isSuccessful(), $mapping->getErrorOutput());

        return [$this->payload($release), $this->payload($mapping)];
    }

    private function race(int $versionId, string $firstScript, string $secondScript): array
    {
        $barrier = sys_get_temp_dir().'/ipms-gate-race-'.bin2hex(random_bytes(8));
        $first = new Process([PHP_BINARY, '-r', $this->waitScript($barrier).$firstScript], base_path());
        $second = new Process([PHP_BINARY, '-r', $this->waitScript($barrier).$secondScript], base_path());
        $first->setTimeout(15);
        $second->setTimeout(15);

        DB::beginTransaction();
        app(VersionGateLock::class)->acquire($versionId);

        try {
            $first->start();
            $second->start();
            touch($barrier);
            usleep(250_000);
            DB::commit();
            $first->wait();
            $second->wait();
        } finally {
            @unlink($barrier);
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($first->isRunning()) {
                $first->stop();
            }
            if ($second->isRunning()) {
                $second->stop();
            }
        }

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

        return [$this->payload($first), $this->payload($second)];
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>}
     */
    private function stableScopeRace(
        RequirementProject $link,
        ?ProjectVersion $source,
        ProjectVersion $target,
        string $mutationScript,
        string $gateScript,
        User $actor,
    ): array {
        $prefix = sys_get_temp_dir().'/ipms-stable-scope-race-'.bin2hex(random_bytes(8));
        $locked = $prefix.'-locked';
        $proceed = $prefix.'-proceed';
        $planned = $prefix.'-planned';

        $planning = new Process([
            PHP_BINARY,
            '-r',
            $this->planningScript($link, $source, $target, $actor, $locked, $proceed, $planned),
        ], base_path());
        $mutation = new Process([
            PHP_BINARY,
            '-r',
            $this->waitScript($locked).$mutationScript,
        ], base_path());
        $gate = new Process([
            PHP_BINARY,
            '-r',
            $this->waitScript($planned).$gateScript,
        ], base_path());

        foreach ([$planning, $mutation, $gate] as $process) {
            $process->setTimeout(20);
        }

        try {
            $planning->start();
            $this->waitForFile($locked, $planning);
            $mutation->start();
            $gate->start();
            usleep(1_500_000);
            touch($proceed);

            foreach ([$planning, $mutation, $gate] as $process) {
                $process->wait();
            }
        } finally {
            foreach ([$locked, $proceed, $planned] as $path) {
                @unlink($path);
            }
            foreach ([$planning, $mutation, $gate] as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }

        foreach ([$planning, $mutation, $gate] as $process) {
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }

        return [
            $this->payload($planning),
            $this->payload($mutation),
            $this->payload($gate),
        ];
    }

    private function planningScript(
        RequirementProject $link,
        ?ProjectVersion $source,
        ProjectVersion $target,
        User $actor,
        string $locked,
        string $proceed,
        string $planned,
    ): string {
        $sourceLock = $source === null
            ? ''
            : sprintf('app(App\\Services\\VersionGateLock::class)->acquire(%d);', $source->id);

        return sprintf(implode("\n", [
            'require getcwd()."/vendor/autoload.php";',
            '$app=require getcwd()."/bootstrap/app.php";',
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
            'Illuminate\\Support\\Facades\\DB::beginTransaction();',
            'try {',
            ' Illuminate\\Support\\Facades\\DB::select("SELECT pg_advisory_xact_lock(hashtextextended(\'itpms:requirement-project:\' || ?::text || \':\' || ?::text, 0))", [%d, %d]);',
            ' %s',
            ' touch(%s);',
            ' while (!file_exists(%s)) { usleep(1000); }',
            ' app(App\\Services\\ProjectVersionService::class)->assignRequirement(App\\Models\\ProjectVersion::findOrFail(%d), App\\Models\\RequirementProject::findOrFail(%d), 1, App\\Models\\User::findOrFail(%d), "Race planning");',
            ' Illuminate\\Support\\Facades\\DB::commit();',
            ' touch(%s);',
            ' echo json_encode(["result"=>"planned"])."\\n";',
            '} catch (Throwable $e) {',
            ' if (Illuminate\\Support\\Facades\\DB::transactionLevel() > 0) { Illuminate\\Support\\Facades\\DB::rollBack(); }',
            ' echo json_encode(["result"=>$e instanceof App\\Exceptions\\DomainConflictException ? $e->errorCode : "error","sql_state"=>$e instanceof Illuminate\\Database\\QueryException ? $e->getCode() : null])."\\n";',
            '}',
        ]),
            $link->requirement_id,
            $link->project_id,
            $sourceLock,
            var_export($locked, true),
            var_export($proceed, true),
            $target->id,
            $link->id,
            $actor->id,
            var_export($planned, true),
        );
    }

    private function taskInsertForLinkScript(int $linkId): string
    {
        return sprintf(implode("\n", [
            'Illuminate\\Support\\Facades\\DB::beginTransaction();',
            'try {',
            ' $link=App\\Models\\RequirementProject::findOrFail(%d);',
            ' App\\Models\\Task::factory()->create(["requirement_id"=>$link->requirement_id,"project_id"=>$link->project_id,"status"=>App\\Enums\\TaskStatus::TODO]);',
            ' usleep(4000000);',
            ' Illuminate\\Support\\Facades\\DB::commit();',
            ' echo json_encode(["result"=>"mutated"])."\\n";',
            '} catch (Illuminate\\Database\\QueryException $e) {',
            ' if (Illuminate\\Support\\Facades\\DB::transactionLevel() > 0) { Illuminate\\Support\\Facades\\DB::rollBack(); }',
            ' $c=App\\Services\\VersionGateLock::mutationConflict($e);',
            ' echo json_encode(["result"=>$c?->errorCode ?? "db_error","sql_state"=>$e->getCode()])."\\n";',
            '}',
        ]), $linkId);
    }

    private function defectInsertForLinkScript(int $linkId): string
    {
        return sprintf(implode("\n", [
            'Illuminate\\Support\\Facades\\DB::beginTransaction();',
            'try {',
            ' $link=App\\Models\\RequirementProject::findOrFail(%d);',
            ' App\\Models\\Defect::factory()->create(["requirement_id"=>$link->requirement_id,"project_id"=>$link->project_id,"severity"=>App\\Enums\\DefectSeverity::SERIOUS,"status"=>App\\Enums\\DefectStatus::REOPENED]);',
            ' usleep(4000000);',
            ' Illuminate\\Support\\Facades\\DB::commit();',
            ' echo json_encode(["result"=>"mutated"])."\\n";',
            '} catch (Illuminate\\Database\\QueryException $e) {',
            ' if (Illuminate\\Support\\Facades\\DB::transactionLevel() > 0) { Illuminate\\Support\\Facades\\DB::rollBack(); }',
            ' $c=App\\Services\\VersionGateLock::mutationConflict($e);',
            ' echo json_encode(["result"=>$c?->errorCode ?? "db_error","sql_state"=>$e->getCode()])."\\n";',
            '}',
        ]), $linkId);
    }

    private function transitionAfterPlanningScript(int $versionId, int $actorId): string
    {
        return sprintf(implode("\n", [
            'try {',
            ' $version=App\\Models\\ProjectVersion::findOrFail(%d);',
            ' app(App\\Services\\ProjectVersionService::class)->transition($version, App\\Enums\\ProjectVersionStatus::READY_TO_RELEASE, $version->lock_version, App\\Models\\User::findOrFail(%d));',
            ' echo json_encode(["result"=>"ready"])."\\n";',
            '} catch (App\\Exceptions\\DomainConflictException $e) { echo json_encode(["result"=>$e->errorCode])."\\n";',
            '} catch (Illuminate\\Database\\QueryException $e) { echo json_encode(["result"=>"db_error","sql_state"=>$e->getCode()])."\\n"; }',
        ]), $versionId, $actorId);
    }

    private function releaseAfterPlanningScript(int $versionId, int $actorId): string
    {
        return sprintf(implode("\n", [
            'try {',
            ' $version=App\\Models\\ProjectVersion::findOrFail(%d);',
            ' $version=app(App\\Services\\ProjectVersionService::class)->transition($version, App\\Enums\\ProjectVersionStatus::READY_TO_RELEASE, $version->lock_version, App\\Models\\User::findOrFail(%d));',
            ' app(App\\Services\\ProjectReleaseService::class)->release($version, App\\Models\\User::findOrFail(%d), ["lock_version"=>$version->lock_version,"release_notes"=>"Race","force"=>false]);',
            ' echo json_encode(["result"=>"released"])."\\n";',
            '} catch (App\\Exceptions\\DomainConflictException $e) { echo json_encode(["result"=>$e->errorCode])."\\n";',
            '} catch (Illuminate\\Database\\QueryException $e) { echo json_encode(["result"=>"db_error","sql_state"=>$e->getCode()])."\\n"; }',
        ]), $versionId, $actorId, $actorId);
    }

    private function waitForFile(string $path, Process $process): void
    {
        $deadline = microtime(true) + 5;
        while (! file_exists($path) && microtime(true) < $deadline) {
            if (! $process->isRunning()) {
                break;
            }
            usleep(10_000);
        }

        $this->assertFileExists($path, $process->getOutput().$process->getErrorOutput());
    }

    private function waitForAdvisoryWaitOrCompletion(int $pid, Process $process): void
    {
        $deadline = microtime(true) + 5;
        while ($process->isRunning() && microtime(true) < $deadline) {
            $activity = DB::table('pg_stat_activity')
                ->where('pid', $pid)
                ->first(['wait_event_type', 'wait_event']);

            if ($activity?->wait_event_type === 'Lock' && $activity?->wait_event === 'advisory') {
                return;
            }

            usleep(10_000);
        }

        if ($process->isRunning()) {
            $this->fail('Mapping transaction did not reach the advisory lock wait.');
        }
    }

    private function releaseHoldingScopeScript(
        ProjectVersion $version,
        RequirementProject $link,
        User $admin,
        string $scopeLocked,
        string $proceed,
    ): string {
        return sprintf(implode("\n", [
            'require getcwd()."/vendor/autoload.php";',
            '$app=require getcwd()."/bootstrap/app.php";',
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
            'Illuminate\\Support\\Facades\\DB::beginTransaction();',
            'try {',
            ' app(App\\Services\\VersionGateLock::class)->acquireScope(%d, %d);',
            ' touch(%s);',
            ' while (!file_exists(%s)) { usleep(1000); }',
            ' app(App\\Services\\ProjectReleaseService::class)->release(App\\Models\\ProjectVersion::findOrFail(%d), App\\Models\\User::findOrFail(%d), ["lock_version"=>1,"release_notes"=>"Race","force"=>true,"force_reason"=>"Race"]);',
            ' Illuminate\\Support\\Facades\\DB::commit();',
            ' echo json_encode(["result"=>"released"])."\\n";',
            '} catch (Throwable $e) {',
            ' if (Illuminate\\Support\\Facades\\DB::transactionLevel() > 0) { Illuminate\\Support\\Facades\\DB::rollBack(); }',
            ' echo json_encode(["result"=>$e instanceof App\\Exceptions\\DomainConflictException ? $e->errorCode : "db_error","sql_state"=>$e instanceof Illuminate\\Database\\QueryException ? $e->getCode() : null])."\\n";',
            '}',
        ]),
            $link->requirement_id,
            $link->project_id,
            var_export($scopeLocked, true),
            var_export($proceed, true),
            $version->id,
            $admin->id,
        );
    }

    private function directMappingScript(int $linkId, string $operation, string $pidFile): string
    {
        $statement = $operation === 'delete'
            ? sprintf('Illuminate\\Support\\Facades\\DB::table("requirement_project")->where("id", %d)->delete();', $linkId)
            : sprintf('Illuminate\\Support\\Facades\\DB::table("requirement_project")->where("id", %d)->update(["project_version_id"=>null]);', $linkId);

        return sprintf(implode("\n", [
            'require getcwd()."/vendor/autoload.php";',
            '$app=require getcwd()."/bootstrap/app.php";',
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
            'Illuminate\\Support\\Facades\\DB::beginTransaction();',
            'try {',
            ' file_put_contents(%s, (string) Illuminate\\Support\\Facades\\DB::selectOne("SELECT pg_backend_pid() AS pid")->pid);',
            ' %s',
            ' Illuminate\\Support\\Facades\\DB::commit();',
            ' echo json_encode(["result"=>"mapped"])."\\n";',
            '} catch (Illuminate\\Database\\QueryException $e) {',
            ' if (Illuminate\\Support\\Facades\\DB::transactionLevel() > 0) { Illuminate\\Support\\Facades\\DB::rollBack(); }',
            ' $conflict=App\\Services\\VersionGateLock::mutationConflict($e);',
            ' echo json_encode(["result"=>$conflict?->errorCode ?? "db_error","sql_state"=>$e->getCode()])."\\n";',
            '}',
        ]), var_export($pidFile, true), $statement);
    }

    private function waitScript(string $barrier): string
    {
        return sprintf(implode("\n", [
            'require getcwd()."/vendor/autoload.php";',
            '$app=require getcwd()."/bootstrap/app.php";',
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
            'while (!file_exists(%s)) { usleep(1000); }',
        ])."\n", var_export($barrier, true));
    }

    private function transitionScript(int $versionId, int $actorId): string
    {
        return sprintf(implode("\n", [
            'try {',
            ' app(App\\Services\\ProjectVersionService::class)->transition(App\\Models\\ProjectVersion::findOrFail(%d), App\\Enums\\ProjectVersionStatus::READY_TO_RELEASE, 1, App\\Models\\User::findOrFail(%d));',
            ' echo json_encode(["result"=>"ready"])."\\n";',
            '} catch (App\\Exceptions\\DomainConflictException $e) { echo json_encode(["result"=>$e->errorCode])."\\n";',
            '} catch (Illuminate\\Database\\QueryException $e) { echo json_encode(["result"=>"db_error","sql_state"=>$e->getCode()])."\\n"; }',
        ]), $versionId, $actorId);
    }

    private function taskInsertScript(int $versionId): string
    {
        return sprintf(implode("\n", [
            'try { App\\Models\\Task::factory()->forVersionScope(App\\Models\\ProjectVersion::findOrFail(%d))->create(["status"=>App\\Enums\\TaskStatus::TODO]);',
            ' echo json_encode(["result"=>"mutated"])."\\n";',
            '} catch (Illuminate\\Database\\QueryException $e) { $c=App\\Services\\VersionGateLock::mutationConflict($e); echo json_encode(["result"=>$c?->errorCode ?? "db_error","sql_state"=>$e->getCode()])."\\n"; }',
        ]), $versionId);
    }

    private function defectReopenScript(int $defectId): string
    {
        return sprintf(implode("\n", [
            'try { App\\Models\\Defect::findOrFail(%d)->update(["status"=>App\\Enums\\DefectStatus::REOPENED]);',
            ' echo json_encode(["result"=>"mutated"])."\\n";',
            '} catch (Illuminate\\Database\\QueryException $e) { $c=App\\Services\\VersionGateLock::mutationConflict($e); echo json_encode(["result"=>$c?->errorCode ?? "db_error","sql_state"=>$e->getCode()])."\\n"; }',
        ]), $defectId);
    }

    private function releaseScript(int $versionId, int $actorId, bool $force): string
    {
        return sprintf(implode("\n", [
            'try { app(App\\Services\\ProjectReleaseService::class)->release(App\\Models\\ProjectVersion::findOrFail(%d), App\\Models\\User::findOrFail(%d), ["lock_version"=>1,"release_notes"=>"Race","force"=>%s,"force_reason"=>%s]);',
            ' echo json_encode(["result"=>"released"])."\\n";',
            '} catch (App\\Exceptions\\DomainConflictException $e) { echo json_encode(["result"=>$e->errorCode])."\\n";',
            '} catch (Illuminate\\Database\\QueryException $e) { echo json_encode(["result"=>"db_error","sql_state"=>$e->getCode()])."\\n"; }',
        ]), $versionId, $actorId, $force ? 'true' : 'false', $force ? '"Race"' : 'null');
    }

    private function deliveryScript(int $requirementId, int $projectId, int $actorId): string
    {
        return sprintf(implode("\n", [
            'try { app(App\\Services\\RequirementWorkflowService::class)->transitionProjectDelivery(App\\Models\\Requirement::findOrFail(%d), %d, App\\Enums\\ProjectDeliveryStatus::PENDING_DEPLOY, App\\Models\\User::findOrFail(%d));',
            ' echo json_encode(["result"=>"transitioned"])."\\n";',
            '} catch (App\\Exceptions\\DomainConflictException $e) { echo json_encode(["result"=>$e->errorCode])."\\n";',
            '} catch (Illuminate\\Database\\QueryException $e) { echo json_encode(["result"=>"db_error","sql_state"=>$e->getCode()])."\\n"; }',
        ]), $requirementId, $projectId, $actorId);
    }

    private function resubmitScript(int $requirementId, int $actorId, int $projectId): string
    {
        return sprintf(implode("\n", [
            'try { app(App\\Services\\RequirementWorkflowService::class)->resubmit(App\\Models\\Requirement::findOrFail(%d), App\\Models\\User::findOrFail(%d), ["title"=>"Race revision","project_ids"=>[%d]]);',
            ' echo json_encode(["result"=>"resubmitted"])."\\n";',
            '} catch (App\\Exceptions\\DomainConflictException $e) { echo json_encode(["result"=>$e->errorCode])."\\n";',
            '} catch (Illuminate\\Database\\QueryException $e) { echo json_encode(["result"=>"db_error","sql_state"=>$e->getCode()])."\\n"; }',
        ]), $requirementId, $actorId, $projectId);
    }

    private function payload(Process $process): array
    {
        $payload = json_decode(trim($process->getOutput()), true);
        $this->assertIsArray($payload, $process->getOutput().$process->getErrorOutput());

        return $payload;
    }

    private function assertNoDeadlock(array ...$payloads): void
    {
        foreach ($payloads as $payload) {
            $this->assertNotSame(
                '40P01',
                $payload['sql_state'] ?? null,
                json_encode($payload, JSON_THROW_ON_ERROR),
            );
        }
    }
}
