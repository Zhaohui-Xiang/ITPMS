<?php

namespace Tests\Feature\Api;

use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\RequirementStatus;
use App\Enums\TaskStatus;
use App\Models\Defect;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoreAllowedActionsTest extends TestCase
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

    public function test_project_actions_are_role_and_assignment_scoped(): void
    {
        $fixture = $this->fixture();
        $expected = [
            'requester' => [],
            'it_pm' => ['create_version'],
            'it_member' => [],
            'supplier_pm' => [],
            'supplier_dev' => [],
            'supplier_tester' => [],
            'superadmin' => ['edit', 'archive'],
        ];

        foreach ($expected as $actor => $actions) {
            $this->actingAs($fixture[$actor])
                ->getJson("/api/projects/{$fixture['project']->id}")
                ->assertOk()
                ->assertJsonPath('data.allowed_actions', $actions);
        }

        $this->actingAs($fixture['unrelated'])
            ->getJson("/api/projects/{$fixture['project']->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_requirement_actions_are_role_state_and_project_scoped(): void
    {
        $fixture = $this->fixture();
        $expected = [
            'requester' => ['edit', 'transition_project'],
            'it_pm' => ['edit', 'transition_project', 'create_task'],
            'it_member' => ['edit', 'transition_project', 'create_task'],
            'supplier_pm' => ['transition_project', 'create_task'],
            'supplier_dev' => [],
            'supplier_tester' => [],
            'superadmin' => ['edit', 'transition_project', 'create_task'],
        ];

        foreach ($expected as $actor => $actions) {
            $this->actingAs($fixture[$actor])
                ->getJson("/api/requirements/{$fixture['requirement']->id}")
                ->assertOk()
                ->assertJsonPath('data.allowed_actions', $actions)
                ->assertJsonPath('data.status', RequirementStatus::ASSIGNED->value)
                ->assertJsonPath('data.status_code', RequirementStatus::ASSIGNED->name)
                ->assertJsonPath('data.status_label', RequirementStatus::ASSIGNED->label())
                ->assertJsonPath('data.priority_code', 'HIGH')
                ->assertJsonPath('data.project_deliveries.0.delivery_status_code', ProjectDeliveryStatus::ASSIGNED->name);
        }

        $this->actingAs($fixture['unrelated'])
            ->getJson("/api/requirements/{$fixture['requirement']->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_task_actions_are_role_state_and_assignee_scoped(): void
    {
        $fixture = $this->fixture();
        $expected = [
            'it_pm' => ['edit', 'transition', 'hold'],
            'it_member' => ['edit'],
            'supplier_pm' => ['edit', 'transition', 'hold'],
            'supplier_dev' => ['claim'],
            'supplier_tester' => [],
            'superadmin' => ['edit', 'claim', 'transition', 'hold'],
        ];

        foreach ($expected as $actor => $actions) {
            $this->actingAs($fixture[$actor])
                ->getJson("/api/tasks/{$fixture['task']->id}")
                ->assertOk()
                ->assertJsonPath('data.allowed_actions', $actions)
                ->assertJsonPath('data.status', TaskStatus::TODO->value)
                ->assertJsonPath('data.status_code', TaskStatus::TODO->name)
                ->assertJsonPath('data.status_label', TaskStatus::TODO->label());
        }

        foreach (['requester', 'unrelated'] as $actor) {
            $this->actingAs($fixture[$actor])
                ->getJson("/api/tasks/{$fixture['task']->id}")
                ->assertForbidden()
                ->assertJsonPath('error_code', 'FORBIDDEN');
        }

        $fixture['task']->update([
            'status' => TaskStatus::IN_PROGRESS->value,
            'assignee_id' => $fixture['supplier_dev']->id,
        ]);

        $this->actingAs($fixture['supplier_dev'])
            ->getJson("/api/tasks/{$fixture['task']->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['transition', 'hold']);
    }

    public function test_defect_actions_are_role_state_and_assignee_scoped(): void
    {
        $fixture = $this->fixture();
        $expected = [
            'requester' => [],
            'it_pm' => ['confirm'],
            'it_member' => ['edit'],
            'supplier_pm' => ['edit'],
            'supplier_dev' => [],
            'supplier_tester' => ['edit'],
            'superadmin' => ['edit', 'confirm'],
        ];

        foreach ($expected as $actor => $actions) {
            $this->actingAs($fixture[$actor])
                ->getJson("/api/defects/{$fixture['defect']->id}")
                ->assertOk()
                ->assertJsonPath('data.allowed_actions', $actions)
                ->assertJsonPath('data.status_code', DefectStatus::PENDING_CONFIRM->name)
                ->assertJsonPath('data.severity_code', 'FATAL')
                ->assertJsonPath('data.severity_label', '致命(P0)');
        }

        $this->actingAs($fixture['unrelated'])
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $fixture['defect']->update(['status' => DefectStatus::CONFIRMED->value]);
        $this->actingAs($fixture['it_pm'])
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertJsonPath('data.allowed_actions', ['assign']);
        $this->actingAs($fixture['supplier_pm'])
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertJsonPath('data.allowed_actions', ['edit', 'assign']);

        $fixture['defect']->update([
            'status' => DefectStatus::FIXING->value,
            'assignee_id' => $fixture['supplier_dev']->id,
        ]);
        $this->actingAs($fixture['supplier_dev'])
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertJsonPath('data.allowed_actions', ['resolve']);

        $fixture['defect']->update([
            'status' => DefectStatus::PENDING_RETEST->value,
        ]);
        $this->actingAs($fixture['supplier_tester'])
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertJsonPath('data.allowed_actions', ['edit', 'verify']);

        $fixture['defect']->update([
            'status' => DefectStatus::CLOSED->value,
            'closed_at' => now(),
        ]);
        $this->actingAs($fixture['supplier_tester'])
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertJsonPath('data.allowed_actions', ['edit', 'reopen']);
        $this->actingAs($fixture['supplier_dev'])
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertJsonPath('data.allowed_actions', []);
    }

    public function test_hidden_actions_are_rejected_before_validation(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['supplier_tester'])
            ->postJson("/api/tasks/{$fixture['task']->id}/claim", ['unexpected' => true])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($fixture['it_member'])
            ->putJson("/api/tasks/{$fixture['task']->id}", [
                'assignee_id' => 'hidden-invalid-user',
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($fixture['unrelated'])
            ->postJson('/api/tasks', [
                'project_id' => $fixture['project']->id,
                'requirement_id' => 'hidden-invalid-requirement',
                'priority' => 999,
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($fixture['unrelated'])
            ->postJson("/api/tasks/{$fixture['task']->id}/status", ['status' => 999])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($fixture['supplier_dev'])
            ->postJson("/api/defects/{$fixture['defect']->id}/confirm", ['unexpected' => true])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($fixture['it_member'])
            ->postJson("/api/defects/{$fixture['defect']->id}/assign", [])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($fixture['unrelated'])
            ->postJson('/api/defects', [
                'project_id' => $fixture['project']->id,
                'requirement_id' => 'hidden-invalid-requirement',
                'severity' => 999,
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $fixture['defect']->update([
            'status' => DefectStatus::CLOSED->value,
            'closed_at' => now(),
        ]);
        $this->actingAs($fixture['supplier_dev'])
            ->postJson("/api/defects/{$fixture['defect']->id}/reopen", [])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_invalid_defect_state_returns_the_stable_domain_conflict(): void
    {
        $fixture = $this->fixture();
        $fixture['defect']->update(['status' => DefectStatus::CONFIRMED->value]);

        $this->actingAs($fixture['it_pm'])
            ->postJson("/api/defects/{$fixture['defect']->id}/confirm")
            ->assertConflict()
            ->assertJsonPath('error_code', 'INVALID_DEFECT_TRANSITION');
    }

    public function test_list_show_and_action_responses_share_resource_shapes(): void
    {
        $fixture = $this->fixture();
        $actor = $fixture['it_pm'];

        foreach ([
            'projects' => $fixture['project']->id,
            'requirements' => $fixture['requirement']->id,
            'tasks' => $fixture['task']->id,
            'defects' => $fixture['defect']->id,
        ] as $endpoint => $id) {
            $show = $this->actingAs($actor)
                ->getJson("/api/{$endpoint}/{$id}")
                ->assertOk();
            $list = $this->actingAs($actor)
                ->getJson("/api/{$endpoint}?page_size=20")
                ->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        'items',
                        'page',
                        'page_size',
                        'total',
                        'total_pages',
                    ],
                ]);

            $this->assertSame(
                array_keys($show->json('data')),
                array_keys($list->json('data.items.0')),
                "The {$endpoint} list and show resource shapes differ.",
            );
        }

        $taskAction = $this->actingAs($fixture['supplier_dev'])
            ->postJson("/api/tasks/{$fixture['task']->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['transition', 'hold']);
        $taskShow = $this->actingAs($fixture['supplier_dev'])
            ->getJson("/api/tasks/{$fixture['task']->id}")
            ->assertOk();
        $this->assertSame(
            array_keys($taskShow->json('data')),
            array_keys($taskAction->json('data')),
        );

        $defectAction = $this->actingAs($actor)
            ->postJson("/api/defects/{$fixture['defect']->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['assign']);
        $defectShow = $this->actingAs($actor)
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertOk();
        $this->assertSame(
            array_keys($defectShow->json('data')),
            array_keys($defectAction->json('data')),
        );
    }

    public function test_resources_expose_aggregates_without_hidden_user_fields(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['it_pm'])
            ->getJson("/api/projects/{$fixture['project']->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.requirements', 1)
            ->assertJsonPath('data.counts.tasks', 1)
            ->assertJsonPath('data.counts.defects', 1)
            ->assertJsonPath('data.counts.versions', 0)
            ->assertJsonPath('data.version_counts.total', 0)
            ->assertJsonMissing(['email' => $fixture['it_pm']->email])
            ->assertJsonMissing(['username' => $fixture['it_pm']->username]);

        $this->actingAs($fixture['it_pm'])
            ->getJson("/api/requirements/{$fixture['requirement']->id}")
            ->assertOk()
            ->assertJsonPath('data.project_deliveries.0.task_progress.total', 1)
            ->assertJsonPath('data.project_deliveries.0.task_progress.completed', 0)
            ->assertJsonPath('data.project_deliveries.0.open_severe_defect_count', 1)
            ->assertJsonMissing(['email' => $fixture['supplier_dev']->email])
            ->assertJsonMissing(['username' => $fixture['supplier_dev']->username]);
    }

    public function test_pending_and_rejected_requirement_actions_match_the_workflow(): void
    {
        $fixture = $this->fixture();
        $fixture['requirement']->update([
            'status' => RequirementStatus::PENDING_REVIEW->value,
            'reviewer_id' => null,
            'review_comment' => null,
            'reviewed_at' => null,
        ]);

        $this->actingAs($fixture['it_pm'])
            ->getJson("/api/requirements/{$fixture['requirement']->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['edit', 'review']);
        $this->actingAs($fixture['requester'])
            ->getJson("/api/requirements/{$fixture['requirement']->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['edit']);

        $fixture['requirement']->update([
            'reviewer_id' => $fixture['it_pm']->id,
            'review_comment' => 'Please revise scope.',
            'reviewed_at' => now(),
        ]);

        $this->actingAs($fixture['it_pm'])
            ->getJson("/api/requirements/{$fixture['requirement']->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', []);
        $this->actingAs($fixture['it_pm'])
            ->putJson("/api/requirements/{$fixture['requirement']->id}", [
                'priority' => 999,
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
        $this->actingAs($fixture['requester'])
            ->getJson("/api/requirements/{$fixture['requirement']->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['edit']);
    }

    public function test_requirement_resources_hide_other_supplier_project_deliveries(): void
    {
        $fixture = $this->fixture();
        $otherSupplier = Organization::query()->create([
            'name' => 'Hidden workflow supplier',
            'org_type' => 2,
            'is_active' => true,
        ]);
        $otherProject = Project::factory()->create([
            'name' => 'Hidden supplier project',
            'supplier_org_id' => $otherSupplier->id,
            'manager_id' => User::factory()->withRole('it_pm')->create()->id,
        ]);
        RequirementProject::factory()
            ->for($fixture['requirement'])
            ->for($otherProject)
            ->create(['delivery_status' => ProjectDeliveryStatus::ASSIGNED]);

        $this->actingAs($fixture['supplier_pm'])
            ->getJson("/api/requirements/{$fixture['requirement']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.projects')
            ->assertJsonCount(1, 'data.project_deliveries')
            ->assertJsonPath('data.projects.0.id', $fixture['project']->id)
            ->assertJsonPath(
                'data.project_deliveries.0.project.id',
                $fixture['project']->id,
            )
            ->assertJsonMissing(['name' => 'Hidden supplier project']);

        $this->actingAs($fixture['requester'])
            ->getJson("/api/requirements/{$fixture['requirement']->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.projects')
            ->assertJsonCount(2, 'data.project_deliveries');
    }

    public function test_defect_creation_requires_an_explicit_linked_project(): void
    {
        $fixture = $this->fixture();
        $payload = [
            'requirement_id' => $fixture['requirement']->id,
            'title' => 'Explicit project defect',
            'description' => 'The defect belongs to one requirement project link.',
            'severity' => 2,
            'defect_type' => 1,
            'discovery_phase' => 2,
        ];

        $this->actingAs($fixture['it_member'])
            ->postJson('/api/defects', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');

        $this->actingAs($fixture['it_member'])
            ->postJson('/api/defects', [
                ...$payload,
                'project_id' => $fixture['project']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.requirement.id', $fixture['requirement']->id)
            ->assertJsonPath('data.project.id', $fixture['project']->id)
            ->assertJsonPath('data.allowed_actions', ['edit']);

        $this->assertDatabaseHas('defects', [
            'requirement_id' => $fixture['requirement']->id,
            'project_id' => $fixture['project']->id,
            'title' => 'Explicit project defect',
            'status' => DefectStatus::PENDING_CONFIRM->value,
            'reporter_id' => $fixture['it_member']->id,
        ]);
    }

    public function test_unapproved_requirement_rejects_task_creation_at_each_endpoint(): void
    {
        $fixture = $this->fixture();
        $fixture['requirement']->update([
            'status' => RequirementStatus::PENDING_REVIEW->value,
        ]);

        $this->actingAs($fixture['it_member'])
            ->postJson('/api/tasks', [
                'requirement_id' => $fixture['requirement']->id,
                'project_id' => $fixture['project']->id,
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($fixture['it_member'])
            ->postJson("/api/requirements/{$fixture['requirement']->id}/tasks", [
                'project_id' => $fixture['project']->id,
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_supplier_tester_must_be_assigned_to_the_project_to_verify(): void
    {
        $fixture = $this->fixture();
        $unassignedTester = User::factory()->withRole('supplier_tester')->create();
        $unassignedTester->organizations()->attach(
            $fixture['project']->supplier_org_id,
            [
                'role_in_org' => 'member',
                'is_primary' => true,
                'assigned_at' => now(),
            ],
        );
        $fixture['defect']->update([
            'status' => DefectStatus::PENDING_RETEST->value,
        ]);

        $this->actingAs($unassignedTester)
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['edit']);

        $this->actingAs($unassignedTester)
            ->postJson("/api/defects/{$fixture['defect']->id}/verify", [
                'result' => 'invalid-result',
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_task_reassignment_requires_the_assign_permission(): void
    {
        $fixture = $this->fixture();
        $role = $fixture['it_pm']->roles()
            ->where('code', 'it_pm')
            ->firstOrFail();
        $permission = Permission::query()
            ->where('code', 'task.assign')
            ->firstOrFail();
        $role->permissions()->detach($permission);
        Cache::flush();

        $this->actingAs($fixture['it_pm'])
            ->putJson("/api/tasks/{$fixture['task']->id}", [
                'assignee_id' => $fixture['supplier_dev']->id,
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->assertNull($fixture['task']->refresh()->assignee_id);
    }

    public function test_browser_multipart_defect_creation_normalizes_integer_fields(): void
    {
        Storage::fake('public');
        $fixture = $this->fixture();

        $response = $this->actingAs($fixture['it_member'])
            ->post('/api/defects', [
                'requirement_id' => (string) $fixture['requirement']->id,
                'project_id' => (string) $fixture['project']->id,
                'title' => 'Multipart screenshot defect',
                'description' => 'Browser FormData sends scalar fields as strings.',
                'severity' => '2',
                'defect_type' => '1',
                'discovery_phase' => '2',
                'screenshot' => UploadedFile::fake()->createWithContent(
                    'browser-evidence.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
                ),
            ], [
                'Accept' => 'application/json',
            ])
            ->assertCreated()
            ->assertJsonPath('data.project.id', $fixture['project']->id)
            ->assertJsonPath('data.requirement.id', $fixture['requirement']->id);

        Storage::disk('public')->assertExists(
            $response->json('data.screenshot'),
        );
    }

    public function test_failed_defect_creation_removes_the_uploaded_screenshot(): void
    {
        Storage::fake('public');
        $fixture = $this->fixture();
        $unlinkedProject = Project::factory()->create([
            'manager_id' => $fixture['it_pm']->id,
            'created_by_id' => $fixture['superadmin']->id,
            'status' => 1,
        ]);
        DB::table('project_members')->insert([
            'project_id' => $unlinkedProject->id,
            'user_id' => $fixture['it_member']->id,
            'role_in_project' => 'member',
            'assigned_by_id' => $fixture['superadmin']->id,
            'assigned_at' => now(),
            'created_at' => now(),
        ]);

        $this->actingAs($fixture['it_member'])
            ->post('/api/defects', [
                'requirement_id' => $fixture['requirement']->id,
                'project_id' => $unlinkedProject->id,
                'title' => 'Unlinked project defect',
                'description' => 'Creation must roll back its uploaded evidence.',
                'severity' => 2,
                'defect_type' => 1,
                'discovery_phase' => 2,
                'screenshot' => UploadedFile::fake()->createWithContent(
                    'evidence.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
                ),
            ], [
                'Accept' => 'application/json',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');

        $this->assertSame(
            [],
            Storage::disk('public')->allFiles('defects/screenshots'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $requester = User::factory()->withRole('requester')->create();
        $itPm = User::factory()->withRole('it_pm')->create();
        $itMember = User::factory()->withRole('it_member')->create();
        $supplierPm = User::factory()->withRole('supplier_pm')->create();
        $supplierDev = User::factory()->withRole('supplier_dev')->create();
        $supplierTester = User::factory()->withRole('supplier_tester')->create();
        $unrelated = User::factory()->withRole('it_member')->create();
        $superadmin = User::factory()->superAdmin()->create();

        $supplier = Organization::query()->create([
            'name' => 'Workflow supplier',
            'org_type' => 2,
            'is_active' => true,
        ]);
        foreach ([$supplierPm, $supplierDev, $supplierTester] as $supplierUser) {
            $supplierUser->organizations()->attach($supplier, [
                'role_in_org' => 'member',
                'is_primary' => true,
                'assigned_at' => now(),
            ]);
        }

        $project = Project::factory()->create([
            'manager_id' => $itPm->id,
            'created_by_id' => $superadmin->id,
            'supplier_org_id' => $supplier->id,
            'status' => 1,
        ]);
        foreach ([
            [$itPm, 'pm'],
            [$itMember, 'member'],
            [$supplierTester, 'member'],
        ] as [$member, $projectRole]) {
            DB::table('project_members')->insert([
                'project_id' => $project->id,
                'user_id' => $member->id,
                'role_in_project' => $projectRole,
                'assigned_by_id' => $superadmin->id,
                'assigned_at' => now(),
                'created_at' => now(),
            ]);
        }

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

        $task = Task::factory()->create([
            'requirement_id' => $requirement->id,
            'project_id' => $project->id,
            'created_by_id' => $itMember->id,
            'status' => TaskStatus::TODO->value,
            'assignee_id' => null,
        ]);
        $defect = Defect::factory()->create([
            'requirement_id' => $requirement->id,
            'project_id' => $project->id,
            'reporter_id' => $itMember->id,
            'created_by_id' => $itMember->id,
            'severity' => 1,
            'status' => DefectStatus::PENDING_CONFIRM->value,
            'assignee_id' => null,
        ]);

        return compact(
            'requester',
            'itPm',
            'itMember',
            'supplierPm',
            'supplierDev',
            'supplierTester',
            'unrelated',
            'superadmin',
            'project',
            'requirement',
            'task',
            'defect',
        ) + [
            'it_pm' => $itPm,
            'it_member' => $itMember,
            'supplier_pm' => $supplierPm,
            'supplier_dev' => $supplierDev,
            'supplier_tester' => $supplierTester,
        ];
    }
}
