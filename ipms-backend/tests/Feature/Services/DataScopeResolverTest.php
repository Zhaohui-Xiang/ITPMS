<?php

namespace Tests\Feature\Services;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Permissions\DataScopeResolver;
use App\Services\Permissions\FieldFilter;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DataScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    private DataScopeResolver $resolver;
    private FieldFilter $fieldFilter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->resolver = app(DataScopeResolver::class);
        $this->fieldFilter = app(FieldFilter::class);
    }

    private function scopeRule(string $subjectKind, int $subjectId, string $module, string $type, array $projectIds = [], ?int $orgAnchor = null): void
    {
        $id = DB::table('data_scopes')->insertGetId([
            $subjectKind => $subjectId,
            'module' => $module,
            'scope_type' => $type,
            'org_id' => $orgAnchor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($projectIds as $projectId) {
            DB::table('data_scope_projects')->insert([
                'data_scope_id' => $id, 'project_id' => $projectId, 'created_at' => now(),
            ]);
        }
    }

    public function test_no_rules_fall_back_to_builtin_scope(): void
    {
        $user = User::factory()->withRole('it_member')->create();
        $this->assertSame(['kind' => 'builtin'], $this->resolver->resolve($user, 'task'));
    }

    public function test_account_layer_overrides_role_layer(): void
    {
        $user = User::factory()->withRole('it_member')->create();
        $project = Project::factory()->create(['manager_id' => $user->id, 'created_by_id' => $user->id]);

        $this->scopeRule('role_id', $user->roles()->value('roles.id'), 'task', 'all');
        $this->scopeRule('user_id', $user->id, 'task', 'projects', [$project->id]);

        $resolved = $this->resolver->resolve($user->refresh(), 'task');
        $this->assertSame('union', $resolved['kind']);
        $this->assertSame([$project->id], $resolved['project_ids']);
        $this->assertFalse($resolved['own']);
        $this->assertSame([], $resolved['org_anchor_ids']);
    }

    public function test_same_layer_union_merges_multiple_subjects(): void
    {
        $user = User::factory()->withRole('supplier_dev')->create();
        $orgA = Organization::query()->create(['name' => 'A供应商', 'org_type' => 2, 'is_active' => true]);
        $orgB = Organization::query()->create(['name' => 'B供应商', 'org_type' => 2, 'is_active' => true]);
        $user->organizations()->attach([$orgA->id, $orgB->id], [
            'role_in_org' => 'member', 'is_primary' => false, 'assigned_at' => now(),
        ]);
        $project = Project::factory()->create(['manager_id' => $user->id, 'created_by_id' => $user->id]);

        // 组织层：A=org_subtree 锚点 + B=projects 明细 → 并集
        $this->scopeRule('organization_id', $orgA->id, 'defect', 'org_subtree', [], $orgA->id);
        $this->scopeRule('organization_id', $orgB->id, 'defect', 'projects', [$project->id]);

        $resolved = $this->resolver->resolve($user->refresh(), 'defect');
        $this->assertSame('union', $resolved['kind']);
        $this->assertSame([$orgA->id], $resolved['org_anchor_ids']);
        $this->assertSame([$project->id], $resolved['project_ids']);
    }

    public function test_inactive_organization_contributes_no_scope_rules(): void
    {
        $user = User::factory()->withRole('supplier_dev')->create();
        $org = Organization::query()->create(['name' => '停用组织', 'org_type' => 2, 'is_active' => false]);
        $user->organizations()->attach($org->id, [
            'role_in_org' => 'member', 'is_primary' => true, 'assigned_at' => now(),
        ]);
        $this->scopeRule('organization_id', $org->id, 'defect', 'all');

        $this->assertSame(['kind' => 'builtin'], $this->resolver->resolve($user->refresh(), 'defect'));
    }

    public function test_all_and_none_layers(): void
    {
        $user = User::factory()->withRole('it_member')->create();
        $this->scopeRule('user_id', $user->id, 'audit', 'all');
        $this->assertSame(['kind' => 'all'], $this->resolver->resolve($user->refresh(), 'audit'));

        $other = User::factory()->withRole('it_member')->create();
        $this->scopeRule('user_id', $other->id, 'audit', 'none');
        $this->assertSame(['kind' => 'none'], $this->resolver->resolve($other->refresh(), 'audit'));
    }

    public function test_field_filter_strictest_effect_wins(): void
    {
        $user = User::factory()->withRole('it_member')->create();
        $roleId = $user->roles()->value('roles.id');

        $this->assertSame('editable', $this->fieldFilter->resolve($user, 'requirement', 'title'), '无配置默认可编辑');

        DB::table('field_permissions')->insert([
            ['user_id' => $user->id, 'resource' => 'requirement', 'field' => 'title', 'effect' => 'readonly', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->assertSame('readonly', $this->fieldFilter->resolve($user->refresh(), 'requirement', 'title'));

        DB::table('field_permissions')->insert([
            ['role_id' => $roleId, 'resource' => 'requirement', 'field' => 'title', 'effect' => 'masked', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->assertSame('masked', $this->fieldFilter->resolve($user->refresh(), 'requirement', 'title'), '角色 masked 严于账户 readonly');
    }
}
