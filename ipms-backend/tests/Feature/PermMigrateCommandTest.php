<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PermMigrateCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_backfill_then_verify_passes_and_is_idempotent(): void
    {
        $legacyCount = DB::table('permission_role')->count();
        $this->assertGreaterThan(0, $legacyCount);

        $this->artisan('ipms:perm-migrate')
            ->expectsOutputToContain('回填完成')
            ->expectsOutputToContain('核对一致')
            ->assertExitCode(0);

        // 角色规则行数与旧表一致
        $this->assertSame(
            $legacyCount,
            DB::table('permission_rules')->whereNotNull('role_id')->count(),
        );

        // 幂等：再次回填不新增
        $this->artisan('ipms:perm-migrate')
            ->expectsOutputToContain('新增 0 条')
            ->assertExitCode(0);

        $this->artisan('ipms:perm-migrate', ['--verify' => true])
            ->expectsOutputToContain('核对一致')
            ->assertExitCode(0);
    }

    public function test_verify_fails_on_divergence(): void
    {
        $this->artisan('ipms:perm-migrate')->assertExitCode(0);

        // 篡改：删掉一条回填规则
        DB::table('permission_rules')->whereNotNull('role_id')->limit(1)->delete();

        $this->artisan('ipms:perm-migrate', ['--verify' => true])
            ->expectsOutputToContain('核对失败')
            ->assertExitCode(1);
    }
}
