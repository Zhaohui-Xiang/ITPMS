<?php

namespace Tests\Feature\Api;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\RequirementStatus;
use App\Models\Defect;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DefectRetestPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_reporter_tester_can_verify_without_project_membership(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['reporterTester'])
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertOk()
            ->assertJsonPath('data.status_code', DefectStatus::PENDING_RETEST->name)
            ->assertJsonPath('data.allowed_actions', ['edit', 'verify']);

        $this->actingAs($fixture['reporterTester'])
            ->postJson("/api/defects/{$fixture['defect']->id}/verify", [
                'result' => 'pass',
                'comment' => '复测通过',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_code', DefectStatus::CLOSED->name);
    }

    public function test_reporter_tester_can_reopen_a_closed_defect_without_project_membership(): void
    {
        $fixture = $this->fixture();
        $fixture['defect']->update([
            'status' => DefectStatus::CLOSED->value,
            'closed_at' => now(),
        ]);

        $this->actingAs($fixture['reporterTester'])
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['edit', 'reopen']);

        $this->actingAs($fixture['reporterTester'])
            ->postJson("/api/defects/{$fixture['defect']->id}/reopen", [
                'reason' => '复测未通过，需要重开',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_code', DefectStatus::REOPENED->name);
    }

    public function test_member_tester_keeps_retest_permission(): void
    {
        $fixture = $this->fixture();
        DB::table('project_members')->insert([
            'project_id' => $fixture['project']->id,
            'user_id' => $fixture['memberTester']->id,
            'role_in_project' => 'member',
            'assigned_by_id' => $fixture['itPm']->id,
            'assigned_at' => now(),
            'created_at' => now(),
        ]);

        $this->actingAs($fixture['memberTester'])
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['edit', 'verify']);
    }

    public function test_unrelated_tester_cannot_verify(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['otherTester'])
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['edit']);

        $this->actingAs($fixture['otherTester'])
            ->postJson("/api/defects/{$fixture['defect']->id}/verify", [
                'result' => 'pass',
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    private function fixture(): array
    {
        $itPm = User::factory()->withRole('it_pm')->create();
        $requester = User::factory()->withRole('requester')->create();
        $reporterTester = User::factory()->withRole('supplier_tester')->create();
        $memberTester = User::factory()->withRole('supplier_tester')->create();
        $otherTester = User::factory()->withRole('supplier_tester')->create();

        $supplier = Organization::query()->create([
            'name' => 'Retest supplier',
            'org_type' => 2,
            'is_active' => true,
        ]);
        foreach ([$reporterTester, $memberTester, $otherTester] as $tester) {
            $tester->organizations()->attach($supplier, [
                'role_in_org' => 'member',
                'is_primary' => true,
                'assigned_at' => now(),
            ]);
        }

        $project = Project::factory()->create([
            'name' => 'Retest boundary project',
            'manager_id' => $itPm->id,
            'supplier_org_id' => $supplier->id,
            'created_by_id' => $itPm->id,
            'status' => 1,
        ]);

        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::ASSIGNED->value,
            'priority' => 2,
        ]);
        RequirementProject::factory()
            ->for($requirement)
            ->for($project)
            ->create(['delivery_status' => ProjectDeliveryStatus::ASSIGNED]);

        $defect = Defect::factory()->create([
            'requirement_id' => $requirement->id,
            'project_id' => $project->id,
            'title' => 'Reporter retest boundary defect',
            'reporter_id' => $reporterTester->id,
            'created_by_id' => $reporterTester->id,
            'severity' => DefectSeverity::SERIOUS->value,
            'status' => DefectStatus::PENDING_RETEST->value,
        ]);

        return compact(
            'itPm',
            'requester',
            'reporterTester',
            'memberTester',
            'otherTester',
            'supplier',
            'project',
            'requirement',
            'defect',
        );
    }
}
