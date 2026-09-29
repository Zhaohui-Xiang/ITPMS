<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TypeMismatchScanCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_database_reports_zero_mismatches(): void
    {
        $user = User::factory()->withRole('it_pm')->create();
        $org = Organization::query()->create(['name' => '内部组织', 'org_type' => 1, 'is_active' => true]);
        $user->organizations()->attach($org->id, [
            'role_in_org' => 'member', 'is_primary' => true, 'assigned_at' => now(),
        ]);

        $this->artisan('ipms:perm-scan-types')
            ->expectsOutputToContain('未发现类型错配')
            ->assertExitCode(0);
    }

    public function test_mismatched_bindings_are_reported_without_mutation(): void
    {
        // 类型不变量触发器是 DEFERRABLE，测试事务不提交，可直接插入错配行
        $internal = User::factory()->create(['user_type' => 1]);
        $supplierRole = Role::query()->create([
            'name' => '供应商角色', 'code' => 'supplier_tester', 'user_type' => 2, 'is_system' => true,
        ]);
        DB::table('role_user')->insert([
            'user_id' => $internal->id, 'role_id' => $supplierRole->id, 'assigned_at' => now(),
        ]);
        $supplierOrg = Organization::query()->create(['name' => '供应商组织', 'org_type' => 2, 'is_active' => true]);
        DB::table('organization_user')->insert([
            'user_id' => $internal->id, 'organization_id' => $supplierOrg->id,
            'role_in_org' => 'member', 'is_primary' => false, 'assigned_at' => now(),
        ]);

        $this->artisan('ipms:perm-scan-types')
            ->expectsOutputToContain('role_user 错配: 1 条')
            ->expectsOutputToContain('organization_user 错配: 1 条')
            ->assertExitCode(1);

        // 只报告不修复
        $this->assertDatabaseCount('role_user', 1);
        $this->assertDatabaseCount('organization_user', 1);
    }
}
