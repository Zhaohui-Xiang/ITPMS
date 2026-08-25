<?php

namespace Tests\Feature\Services;

use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\RequirementStatus;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
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
            $this->assertNotSame('40P01', $payload['sql_state'] ?? null);
        }
    }
}
