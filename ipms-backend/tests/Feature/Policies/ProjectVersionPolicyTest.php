<?php

namespace Tests\Feature\Policies;

use App\Enums\ProjectVersionStatus;
use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\ProjectVersionHistory;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Policies\DefectPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectVersionPolicy;
use App\Policies\RequirementPolicy;
use App\Policies\TaskPolicy;
use App\Providers\AuthServiceProvider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

    public function test_project_version_permissions_are_synchronized_exactly_and_idempotently(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('module', 'project_version')
            ->pluck('id', 'code');
        $roleIds = DB::table('roles')->pluck('id', 'code');
        $nonVersionBefore = $this->permissionPivots(false);

        DB::table('permission_role')->insert([
            ['role_id' => $roleIds['it_member'], 'permission_id' => $permissionIds['project_version.override']],
            ['role_id' => $roleIds['supplier_pm'], 'permission_id' => $permissionIds['project_version.release']],
            ['role_id' => $roleIds['it_pm'], 'permission_id' => $permissionIds['project_version.override']],
        ]);

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

        $versionAfterFirstRun = $this->permissionPivots(true);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($versionAfterFirstRun, $this->permissionPivots(true));
        $this->assertSame($nonVersionBefore, $this->permissionPivots(false));
    }

    public function test_auth_provider_and_all_policy_mappings_are_explicitly_loaded(): void
    {
        $this->assertTrue(app()->providerIsLoaded(AuthServiceProvider::class));
        $this->assertInstanceOf(ProjectVersionPolicy::class, Gate::getPolicyFor(ProjectVersion::class));
        $this->assertInstanceOf(ProjectPolicy::class, Gate::getPolicyFor(Project::class));
        $this->assertInstanceOf(RequirementPolicy::class, Gate::getPolicyFor(Requirement::class));
        $this->assertInstanceOf(TaskPolicy::class, Gate::getPolicyFor(Task::class));
        $this->assertInstanceOf(DefectPolicy::class, Gate::getPolicyFor(Defect::class));
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

    public function test_supplier_leaf_membership_can_view_the_entire_supplier_tree_in_one_scope_query(): void
    {
        $manager = $this->userWithRole('it_pm');
        $supplier = $this->userWithRole('supplier_dev');

        $root = $this->createOrganization('Supplier A root');
        $branch = $this->createOrganization('Supplier A branch', $root);
        $deepBranch = $this->createOrganization('Supplier A deep branch', $branch);
        $deeperBranch = $this->createOrganization('Supplier A deeper branch', $deepBranch);
        $leaf = $this->createOrganization('Supplier A leaf', $deeperBranch);
        $sibling = $this->createOrganization('Supplier A sibling', $root);
        $otherRoot = $this->createOrganization('Supplier B root');

        $supplier->organizations()->attach($leaf, ['is_primary' => true]);
        Cache::forget("user_{$supplier->id}_supplier_org_ids");

        DB::flushQueryLog();
        DB::enableQueryLog();
        $scopeIds = $supplier->getSupplierDescendantOrgIds();
        $scopeQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        sort($scopeIds);
        $expectedScope = [$root, $branch, $deepBranch, $deeperBranch, $leaf, $sibling];
        sort($expectedScope);

        $this->assertSame($expectedScope, $scopeIds);
        $this->assertSame(1, $scopeQueryCount);

        $rootVersion = $this->versionForSupplierOrganization($manager, $root);
        $leafVersion = $this->versionForSupplierOrganization($manager, $leaf);
        $siblingVersion = $this->versionForSupplierOrganization($manager, $sibling);
        $otherVersion = $this->versionForSupplierOrganization($manager, $otherRoot);

        $this->assertTrue($supplier->can('view', $rootVersion));
        $this->assertTrue($supplier->can('view', $leafVersion));
        $this->assertTrue($supplier->can('view', $siblingVersion));
        $this->assertFalse($supplier->can('view', $otherVersion));
    }

    public function test_each_management_ability_uses_only_its_approved_permission_code(): void
    {
        $abilityPermissions = [
            'create' => 'project_version.create',
            'update' => 'project_version.edit',
            'transition' => 'project_version.transition',
            'release' => 'project_version.release',
            'delete' => 'project_version.edit',
        ];
        $actionCodes = [
            'project_version.create',
            'project_version.edit',
            'project_version.transition',
            'project_version.release',
        ];
        $roleId = DB::table('roles')->where('code', 'it_pm')->value('id');
        $permissionIds = DB::table('permissions')
            ->whereIn('code', $actionCodes)
            ->pluck('id', 'code');

        foreach ($abilityPermissions as $ability => $expectedCode) {
            $this->seed(RolePermissionSeeder::class);
            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissionIds->values())
                ->delete();
            DB::table('permission_role')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionIds[$expectedCode],
            ]);
            Cache::flush();

            [$manager, $project, $version] = $this->managedDraftVersion();
            $this->assertTrue(
                $this->allowsVersionAbility($manager, $project, $version, $ability),
                "{$ability} must accept {$expectedCode}",
            );

            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionIds[$expectedCode])
                ->delete();
            foreach (array_diff($actionCodes, [$expectedCode]) as $wrongCode) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionIds[$wrongCode],
                ]);
            }
            Cache::flush();

            $this->assertFalse(
                $this->allowsVersionAbility($manager, $project, $version, $ability),
                "{$ability} must reject every permission except {$expectedCode}",
            );
        }
    }

    public function test_supplier_user_type_cannot_manage_versions_with_an_it_pm_role_and_permissions(): void
    {
        $supplier = $this->userWithRole('supplier_pm');
        $itPmRole = Role::query()->where('code', 'it_pm')->sole();
        $supplier->roles()->attach($itPmRole);
        Cache::flush();

        $project = Project::factory()->create(['manager_id' => $supplier->id]);
        $version = ProjectVersion::factory()->for($project)->create();

        $this->assertTrue($supplier->hasPermission('project_version.create'));
        $this->assertTrue($supplier->hasPermission('project_version.edit'));
        $this->assertTrue($supplier->hasPermission('project_version.transition'));
        $this->assertTrue($supplier->hasPermission('project_version.release'));

        $this->assertFalse($supplier->can('create', [ProjectVersion::class, $project]));
        $this->assertFalse($supplier->can('update', $version));
        $this->assertFalse($supplier->can('transition', $version));
        $this->assertFalse($supplier->can('release', $version));
        $this->assertFalse($supplier->can('delete', $version));
    }

    /**
     * @return list<string>
     */
    private function permissionPivots(bool $projectVersion): array
    {
        $query = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id');

        $projectVersion
            ? $query->where('permissions.code', 'like', 'project_version.%')
            : $query->where('permissions.code', 'not like', 'project_version.%');

        return $query
            ->orderBy('roles.code')
            ->orderBy('permissions.code')
            ->get(['roles.code as role_code', 'permissions.code as permission_code'])
            ->map(static fn ($pivot): string => "{$pivot->role_code}:{$pivot->permission_code}")
            ->all();
    }

    private function createOrganization(string $name, ?int $parentId = null): int
    {
        return DB::table('organizations')->insertGetId([
            'parent_id' => $parentId,
            'name' => $name,
            'org_type' => 2,
        ]);
    }

    private function versionForSupplierOrganization(User $manager, int $organizationId): ProjectVersion
    {
        $project = Project::factory()->withManager($manager)->create([
            'supplier_org_id' => $organizationId,
        ]);

        return ProjectVersion::factory()->for($project)->create();
    }

    /**
     * @return array{User, Project, ProjectVersion}
     */
    private function managedDraftVersion(): array
    {
        $manager = $this->userWithRole('it_pm');
        $project = Project::factory()->withManager($manager)->create();
        $version = ProjectVersion::factory()->for($project)->create();

        return [$manager, $project, $version];
    }

    private function allowsVersionAbility(
        User $user,
        Project $project,
        ProjectVersion $version,
        string $ability,
    ): bool {
        return $ability === 'create'
            ? $user->can('create', [ProjectVersion::class, $project])
            : $user->can($ability, $version);
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
