<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PermPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_preflight_passes_on_healthy_fixture(): void
    {
        User::factory()->superAdmin()->create();

        $this->artisan('ipms:perm-preflight')
            ->expectsOutputToContain('有效超管')
            ->expectsOutputToContain('类型错配为零')
            ->expectsOutputToContain('注册表完整')
            ->assertExitCode(0);
    }

    public function test_preflight_fails_without_effective_superadmin(): void
    {
        $admin = User::factory()->superAdmin()->create(['is_disabled' => true]);

        $this->artisan('ipms:perm-preflight')
            ->expectsOutputToContain('有效超管为 0')
            ->assertExitCode(1);

        // 恢复后通过
        $admin->update(['is_disabled' => false]);
        $this->artisan('ipms:perm-preflight')->assertExitCode(0);
    }

    public function test_preflight_fails_on_type_mismatch(): void
    {
        User::factory()->superAdmin()->create();
        $internal = User::factory()->create(['user_type' => 1]);
        // 测试事务内 DEFERRABLE 触发器不触发，可直接构造错配
        DB::table('role_user')->insert([
            'user_id' => $internal->id,
            'role_id' => DB::table('roles')->where('code', 'supplier_dev')->value('id'),
            'assigned_at' => now(),
        ]);

        $this->artisan('ipms:perm-preflight')
            ->expectsOutputToContain('类型错配')
            ->assertExitCode(1);
    }
}
