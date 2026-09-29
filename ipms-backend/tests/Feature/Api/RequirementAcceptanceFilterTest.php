<?php

namespace Tests\Feature\Api;

use App\Enums\ProjectDeliveryStatus;
use App\Enums\RequirementStatus;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class RequirementAcceptanceFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_pending_acceptance_filter_only_returns_deployed_deliveries(): void
    {
        $requester = User::factory()->withRole('requester')->create();
        $pm = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->create([
            'manager_id' => $pm->id, 'created_by_id' => $pm->id, 'status' => 1,
        ]);

        $deployed = Requirement::factory()->create([
            'title' => '待我验收的需求',
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::DEPLOYED->value,
        ]);
        RequirementProject::factory()->for($deployed)->for($project)
            ->create(['delivery_status' => ProjectDeliveryStatus::DEPLOYED->value]);

        $inProgress = Requirement::factory()->create([
            'title' => '开发中的需求',
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'status' => RequirementStatus::IN_DEVELOPMENT->value,
        ]);
        RequirementProject::factory()->for($inProgress)->for($project)
            ->create(['delivery_status' => ProjectDeliveryStatus::IN_DEVELOPMENT->value]);

        $response = $this->actingAs($requester)
            ->getJson('/api/requirements?pending_acceptance=1')
            ->assertOk();

        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertSame([$deployed->id], $ids);

        // 不加过滤时两条都在（数据范围不受影响）
        $all = $this->actingAs($requester)->getJson('/api/requirements')->assertOk();
        $this->assertCount(2, $all->json('data.items'));
    }

    public function test_terminal_requirements_are_frozen_for_editing(): void
    {
        $requester = User::factory()->withRole('requester')->create();
        $pm = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->create([
            'manager_id' => $pm->id, 'created_by_id' => $pm->id, 'status' => 1,
        ]);

        foreach ([RequirementStatus::DEPLOYED, RequirementStatus::ACCEPTED] as $status) {
            $requirement = Requirement::factory()->create([
                'title' => '终态需求 '.$status->value,
                'submitter_id' => $requester->id,
                'created_by_id' => $requester->id,
                'status' => $status->value,
            ]);
            RequirementProject::factory()->for($requirement)->for($project)
                ->create(['delivery_status' => ProjectDeliveryStatus::DEPLOYED->value]);

            // 列表/详情不再暴露 edit 动作
            $response = $this->actingAs($pm)
                ->getJson("/api/requirements/{$requirement->id}")
                ->assertOk();
            $this->assertNotContains('edit', $response->json('data.allowed_actions'));

            // 提交人/PM 直接写均被拒
            $this->actingAs($requester)
                ->putJson("/api/requirements/{$requirement->id}", ['title' => '试图修改'])
                ->assertForbidden()
                ->assertJsonPath('error_code', 'FORBIDDEN');
            $this->actingAs($pm)
                ->putJson("/api/requirements/{$requirement->id}", ['title' => '试图修改'])
                ->assertForbidden();
        }
    }

    public function test_requirement_type_label_is_returned(): void
    {
        $requester = User::factory()->withRole('requester')->create();
        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
            'created_by_id' => $requester->id,
            'requirement_type' => 1,
        ]);

        $this->actingAs($requester)
            ->getJson("/api/requirements/{$requirement->id}")
            ->assertOk()
            ->assertJsonPath('data.requirement_type', 1)
            ->assertJsonPath('data.requirement_type_label', '功能需求');
    }
}
