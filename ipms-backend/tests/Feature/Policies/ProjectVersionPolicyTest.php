<?php

namespace Tests\Feature\Policies;

use App\Enums\ProjectVersionStatus;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\ProjectVersionHistory;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectVersionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_project_version_permissions_are_seeded_idempotently_with_the_approved_role_matrix(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $expected = [
            'project_version.view' => [
                'it_member',
                'it_pm',
                'requester',
                'super_admin',
                'supplier_dev',
                'supplier_pm',
                'supplier_tester',
            ],
            'project_version.create' => ['it_pm', 'super_admin'],
            'project_version.edit' => ['it_pm', 'super_admin'],
            'project_version.transition' => ['it_pm', 'super_admin'],
            'project_version.release' => ['it_pm', 'super_admin'],
            'project_version.override' => ['super_admin'],
        ];

        $this->assertSame(6, DB::table('permissions')
            ->where('module', 'project_version')
            ->count());

        foreach ($expected as $permissionCode => $roleCodes) {
            $actualRoleCodes = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->join('roles', 'roles.id', '=', 'permission_role.role_id')
                ->where('permissions.code', $permissionCode)
                ->orderBy('roles.code')
                ->pluck('roles.code')
                ->all();

            sort($roleCodes);
            $this->assertSame($roleCodes, $actualRoleCodes, $permissionCode);
        }
    }

    public function test_only_assigned_internal_it_pm_can_create_update_transition_release_and_delete(): void
    {
        $manager = $this->userWithRole('it_pm');
        $otherManager = $this->userWithRole('it_pm');
        $supplierManager = $this->userWithRole('supplier_pm');
        $internalMember = $this->userWithRole('it_member');
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()->for($project)->create([
            'status' => ProjectVersionStatus::DRAFT->value,
        ]);

        $this->assertTrue($manager->can('create', [ProjectVersion::class, $project]));
        $this->assertTrue($manager->can('update', $version));
        $this->assertTrue($manager->can('transition', $version));
        $this->assertTrue($manager->can('release', $version));
        $this->assertTrue($manager->can('delete', $version));

        $this->assertFalse($otherManager->can('create', [ProjectVersion::class, $project]));
        $this->assertFalse($otherManager->can('update', $version));
        $this->assertFalse($otherManager->can('transition', $version));
        $this->assertFalse($otherManager->can('release', $version));
        $this->assertFalse($otherManager->can('delete', $version));

        $supplierProject = Project::factory()->create([
            'manager_id' => $supplierManager->id,
        ]);
        $supplierVersion = ProjectVersion::factory()->for($supplierProject)->create();

        $this->assertFalse($supplierManager->can('create', [ProjectVersion::class, $supplierProject]));
        $this->assertFalse($supplierManager->can('update', $supplierVersion));
        $this->assertFalse($supplierManager->can('transition', $supplierVersion));
        $this->assertFalse($supplierManager->can('release', $supplierVersion));
        $this->assertFalse($supplierManager->can('delete', $supplierVersion));

        $memberManagedProject = Project::factory()->create([
            'manager_id' => $internalMember->id,
        ]);
        $memberManagedVersion = ProjectVersion::factory()->for($memberManagedProject)->create();

        $this->assertFalse($internalMember->can('create', [ProjectVersion::class, $memberManagedProject]));
        $this->assertFalse($internalMember->can('update', $memberManagedVersion));
        $this->assertFalse($internalMember->can('transition', $memberManagedVersion));
        $this->assertFalse($internalMember->can('release', $memberManagedVersion));
        $this->assertFalse($internalMember->can('delete', $memberManagedVersion));
    }

    public function test_super_admin_is_limited_to_view_and_force_release(): void
    {
        $manager = $this->userWithRole('it_pm');
        $superAdmin = $this->userWithRole('super_admin');
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()->for($project)->create();

        $this->assertTrue($superAdmin->can('view', $version));
        $this->assertTrue($superAdmin->can('forceRelease', $version));
        $this->assertFalse($superAdmin->can('create', [ProjectVersion::class, $project]));
        $this->assertFalse($superAdmin->can('update', $version));
        $this->assertFalse($superAdmin->can('transition', $version));
        $this->assertFalse($superAdmin->can('release', $version));
        $this->assertFalse($superAdmin->can('delete', $version));
    }

    public function test_override_permission_cannot_grant_force_release_to_a_non_super_admin(): void
    {
        $manager = $this->userWithRole('it_pm');
        $overridePermissionId = DB::table('permissions')
            ->where('code', 'project_version.override')
            ->value('id');

        DB::table('permission_role')->insertOrIgnore([
            'role_id' => $manager->roles()->sole()->id,
            'permission_id' => $overridePermissionId,
        ]);

        $version = ProjectVersion::factory()->create();

        $this->assertTrue($manager->hasPermission('project_version.override'));
        $this->assertFalse($manager->can('forceRelease', $version));
    }

    public function test_delete_requires_draft_without_requirement_links_or_history(): void
    {
        $manager = $this->userWithRole('it_pm');
        $project = Project::factory()->withManager($manager)->create();

        $nonDraft = ProjectVersion::factory()->for($project)->create([
            'status' => ProjectVersionStatus::PLANNED->value,
        ]);
        $this->assertFalse($manager->can('delete', $nonDraft));

        $linked = ProjectVersion::factory()->for($project)->create();
        RequirementProject::factory()->forVersion($linked)->create();
        $this->assertFalse($manager->can('delete', $linked));

        $withHistory = ProjectVersion::factory()->for($project)->create();
        ProjectVersionHistory::query()->create([
            'project_version_id' => $withHistory->id,
            'event_type' => 'created',
            'from_status' => null,
            'to_status' => ProjectVersionStatus::DRAFT->value,
            'actor_id' => $manager->id,
            'metadata' => [],
        ]);
        $this->assertFalse($manager->can('delete', $withHistory));

        $emptyDraft = ProjectVersion::factory()->for($project)->create();
        $this->assertTrue($manager->can('delete', $emptyDraft));
    }

    public function test_view_reuses_internal_supplier_requester_and_super_admin_project_scope(): void
    {
        $manager = $this->userWithRole('it_pm');
        $internalParticipant = $this->userWithRole('it_member');
        $unrelatedInternal = $this->userWithRole('it_member');
        $supplierParticipant = $this->userWithRole('supplier_dev');
        $unrelatedSupplier = $this->userWithRole('supplier_dev');
        $requester = $this->userWithRole('requester');
        $unrelatedRequester = $this->userWithRole('requester');
        $superAdmin = $this->userWithRole('super_admin');

        $supplierOrgId = DB::table('organizations')->insertGetId([
            'name' => 'Version policy supplier',
            'org_type' => 2,
        ]);
        $supplierProjectOrgId = DB::table('organizations')->insertGetId([
            'parent_id' => $supplierOrgId,
            'name' => 'Version policy supplier child',
            'org_type' => 2,
        ]);

        $supplierParticipant->organizations()->attach($supplierOrgId, [
            'is_primary' => true,
        ]);

        $project = Project::factory()->withManager($manager)->create([
            'supplier_org_id' => $supplierProjectOrgId,
        ]);
        $version = ProjectVersion::factory()->for($project)->create();

        DB::table('project_members')->insert([
            'project_id' => $project->id,
            'user_id' => $internalParticipant->id,
            'role_in_project' => 'member',
        ]);

        $requirement = Requirement::factory()->create([
            'submitter_id' => $requester->id,
        ]);
        RequirementProject::factory()
            ->for($requirement)
            ->for($project)
            ->create();

        $this->assertTrue($internalParticipant->can('view', $version));
        $this->assertTrue($supplierParticipant->can('view', $version));
        $this->assertTrue($requester->can('view', $version));
        $this->assertTrue($superAdmin->can('view', $version));

        $this->assertFalse($unrelatedInternal->can('view', $version));
        $this->assertFalse($unrelatedSupplier->can('view', $version));
        $this->assertFalse($unrelatedRequester->can('view', $version));
    }

    private function userWithRole(string $roleCode): User
    {
        $role = Role::query()->where('code', $roleCode)->sole();
        $user = User::factory()->create([
            'user_type' => $role->user_type,
        ]);

        $user->roles()->attach($role);

        return $user;
    }
}
