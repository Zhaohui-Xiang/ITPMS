<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Defect;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_internal_pm_and_member_list_and_export_only_their_project_scope_and_own_actions(): void
    {
        $fixture = $this->fixture();

        foreach (['pm', 'member'] as $role) {
            $own = $this->log($fixture[$role], 'user', 'not-a-number');
            $this->assertVisible($fixture[$role], [...$fixture['scoped'], $own->id]);
        }
    }

    public function test_supplier_list_and_export_follow_supplier_tree_without_cross_type_id_leaks(): void
    {
        $fixture = $this->fixture();
        $own = $this->log($fixture['supplier'], 'user', null);

        $this->assertVisible($fixture['supplier'], [...$fixture['scoped'], $own->id]);

        $unrelated = User::factory()->withRole('supplier_pm')->create();
        $this->assertVisible($unrelated, []);
    }

    public function test_requester_sees_own_actions_and_requirement_related_history_but_not_tasks_or_other_requirements(): void
    {
        $fixture = $this->fixture();
        $own = $this->log($fixture['requester'], 'user', 'deleted-target');
        $otherRequirement = Requirement::factory()->create();
        RequirementProject::factory()->for($otherRequirement)->for($fixture['project'])->create();
        $this->log($fixture['writer'], 'requirement', $otherRequirement->id);

        $this->assertVisible($fixture['requester'], [
            $fixture['scoped'][0],
            $fixture['scoped'][1],
            $fixture['scoped'][3],
            $own->id,
        ]);
        $unrelated = User::factory()->withRole('requester')->create();
        $this->assertVisible($unrelated, []);
    }

    public function test_superadmin_sees_all_records_including_unrecognized_or_deleted_targets(): void
    {
        $this->fixture();
        $admin = User::factory()->superAdmin()->create();

        $this->assertVisible($admin, AuditLog::query()->pluck('id')->all());
    }

    public function test_list_and_export_apply_identical_filters_without_bypassing_authorization(): void
    {
        $fixture = $this->fixture();
        $wanted = AuditLog::query()->findOrFail($fixture['scoped'][1]);
        $wanted->update([
            'user_display_name' => 'Snapshot operator',
            'target_name' => 'Needle requirement',
            'created_at' => '2026-09-08 23:59:59.500000+00',
        ]);
        $this->log($fixture['writer'], 'requirement', $wanted->target_id, [
            'created_at' => '2026-09-09 00:00:00+00',
        ]);

        $filters = [
            'module' => $wanted->module,
            'action_type' => $wanted->action_type,
            'user_id' => $fixture['writer']->id,
            'target_type' => 'requirement',
            'keyword' => 'Needle',
            'date_from' => '2026-09-08',
            'date_to' => '2026-09-08',
            'project_id' => $fixture['project']->id,
        ];
        foreach (['pm', 'supplier', 'requester'] as $role) {
            $this->assertVisible($fixture[$role], [$wanted->id], $filters);
            $this->assertVisible($fixture[$role], [$wanted->id], [
                'keyword' => 'Snapshot operator',
            ]);
            foreach ([
                'module' => 7,
                'action_type' => 10,
                'user_id' => $fixture[$role]->id,
                'target_type' => 'task',
                'keyword' => 'absent',
                'date_from' => '2026-09-09',
                'project_id' => $fixture['foreign']->id,
            ] as $key => $value) {
                $overrides = array_replace($filters, [$key => $value]);
                if ($key === 'date_from') {
                    $overrides['date_to'] = '2026-09-10';
                }
                $this->assertVisible($fixture[$role], [], $overrides);
            }
        }
    }

    public function test_pagination_is_database_backed_stable_and_empty_filters_are_ignored(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['pm'])
            ->getJson('/api/audit-logs?page=2&page_size=2&module=&keyword=&date_to=')
            ->assertOk()
            ->assertJsonPath('data.page', 2)
            ->assertJsonPath('data.page_size', 2)
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.total_pages', 2)
            ->assertJsonPath('data.items.0.id', $fixture['scoped'][1])
            ->assertJsonPath('data.items.1.id', $fixture['scoped'][0]);
    }

    public function test_both_endpoints_reject_invalid_filter_values(): void
    {
        $actor = User::factory()->superAdmin()->create();
        foreach (['/api/audit-logs', '/api/audit-logs/export'] as $endpoint) {
            foreach ([
                ['module' => 'project'],
                ['action_type' => 11],
                ['user_id' => 'invalid'],
                ['project_id' => -1],
                ['date_from' => 'invalid'],
                ['date_from' => '2026-09-09', 'date_to' => '2026-09-08'],
            ] as $filters) {
                $this->actingAs($actor)->getJson($endpoint.'?'.http_build_query($filters))
                    ->assertUnprocessable();
            }
        }
    }

    public function test_csv_cells_are_formula_safe_and_preserve_commas_quotes_and_newlines(): void
    {
        $actor = User::factory()->superAdmin()->create();
        foreach (['=1+1', '+SUM(1,2)', '-1+1', '@SUM(1,2)', " \t=1+1", "\r=1+1", "\n=1+1"] as $payload) {
            $log = $this->log($actor, 'user', null, [
                'user_display_name' => $payload,
                'target_name' => $payload,
            ]);
            $rows = $this->exportRows($actor, ['keyword' => $payload]);
            $row = collect($rows)->firstWhere(0, (string) $log->id);
            $this->assertSame("'".$payload, $row[1]);
            $this->assertSame("'".$payload, $row[6]);
        }
        $text = "Plain, \"quoted\"\nsecond line";
        $log = $this->log($actor, 'user', null, ['target_name' => $text]);
        $row = collect($this->exportRows($actor))->firstWhere(0, (string) $log->id);
        $this->assertSame($text, $row[6]);
    }

    public function test_export_refuses_more_than_ten_thousand_matches_instead_of_silently_truncating(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $log = $this->log($actor, 'user', null);
        $attributes = $log->getRawOriginal();
        unset($attributes['id']);
        for ($i = 0; $i < 20; $i++) {
            DB::table('audit_logs')->insert(array_fill(0, 500, $attributes));
        }

        $this->actingAs($actor)->getJson('/api/audit-logs/export')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'AUDIT_EXPORT_LIMIT_EXCEEDED');

        $log->update(['target_name' => 'Only filtered result']);
        $this->assertVisible($actor, [$log->id], ['keyword' => 'Only filtered result']);
    }

    private function assertVisible(User $actor, array $expected, array $filters = []): void
    {
        $response = $this->actingAs($actor)
            ->getJson('/api/audit-logs?'.http_build_query([...$filters, 'page_size' => 100]))
            ->assertOk();
        $this->assertEqualsCanonicalizing($expected, array_column($response->json('data.items'), 'id'));
        $this->assertSame(count($expected), $response->json('data.total'));
        $this->assertEqualsCanonicalizing($expected, array_map(
            static fn (array $row): int => (int) $row[0],
            $this->exportRows($actor, $filters),
        ));
    }

    private function exportRows(User $actor, array $filters = []): array
    {
        $response = $this->actingAs($actor)
            ->get('/api/audit-logs/export?'.http_build_query($filters))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $response->streamedContent());
        rewind($stream);
        fgetcsv($stream);
        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    private function log(User $actor, string $type, int|string|null $id, array $overrides = []): AuditLog
    {
        return AuditLog::query()->create(array_replace([
            'user_id' => $actor->id,
            'user_name' => $actor->username,
            'user_display_name' => $actor->display_name,
            'user_type' => $actor->user_type,
            'module' => ['project' => 1, 'requirement' => 2, 'task' => 3, 'defect' => 4][$type] ?? 6,
            'action_type' => 2,
            'target_type' => $type,
            'target_id' => $id === null ? null : (string) $id,
            'target_name' => $type.' '.$id,
            'detail' => ['changes' => ['title' => ['before' => 'Old', 'after' => 'New']]],
            'created_at' => '2026-09-08 12:00:00+00',
        ], $overrides));
    }

    private function fixture(): array
    {
        $pm = User::factory()->withRole('it_pm')->create();
        $member = User::factory()->withRole('it_member')->create();
        $supplier = User::factory()->withRole('supplier_pm')->create();
        $requester = User::factory()->withRole('requester')->create();
        $writer = User::factory()->internal()->create();
        $root = Organization::query()->create(['name' => 'Supplier root', 'org_type' => 2]);
        $child = Organization::query()->create([
            'name' => 'Supplier child', 'org_type' => 2, 'parent_id' => $root->id,
        ]);
        $supplier->organizations()->attach($root, [
            'role_in_org' => 'member', 'is_primary' => true, 'assigned_at' => now(),
        ]);

        $project = Project::factory()->create([
            'id' => 810, 'manager_id' => $pm->id, 'supplier_org_id' => $child->id,
        ]);
        DB::table('project_members')->insert([
            'project_id' => $project->id,
            'user_id' => $member->id, 'role_in_project' => 'member',
            'assigned_by_id' => $writer->id, 'assigned_at' => now(),
        ]);
        $foreign = Project::factory()->create(['id' => 820]);
        $requirement = Requirement::factory()->create(['id' => 910, 'submitter_id' => $requester->id]);
        RequirementProject::factory()->for($requirement)->for($project)->create();
        $task = Task::factory()->create([
            'id' => 920, 'requirement_id' => $requirement->id, 'project_id' => $project->id,
        ]);
        $defect = Defect::factory()->create([
            'id' => 930, 'requirement_id' => $requirement->id, 'project_id' => $project->id,
        ]);

        // Each unrelated resource deliberately has the visible project's ID.
        $foreignRequirement = Requirement::factory()->create(['id' => $project->id]);
        RequirementProject::factory()->for($foreignRequirement)->for($foreign)->create();
        Task::factory()->create([
            'id' => $project->id, 'requirement_id' => $foreignRequirement->id, 'project_id' => $foreign->id,
        ]);
        Defect::factory()->create([
            'id' => $project->id, 'requirement_id' => $foreignRequirement->id, 'project_id' => $foreign->id,
        ]);
        foreach (['requirement', 'task', 'defect', 'user', 'organization', 'project_version'] as $type) {
            $this->log($writer, $type, $project->id);
        }
        $this->log($writer, 'project', $foreign->id);
        $this->log($writer, 'task', 'not-a-number');
        $scoped = [];
        foreach (compact('project', 'requirement', 'task', 'defect') as $type => $resource) {
            $scoped[] = $this->log($writer, $type, $resource->id)->id;
        }

        return compact('pm', 'member', 'supplier', 'requester', 'writer', 'project', 'foreign', 'scoped');
    }
}
