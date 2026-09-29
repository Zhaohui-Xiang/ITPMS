<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Permissions\SuperAdminGuard;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PDO;
use PDOException;
use Tests\TestCase;

final class SuperAdminGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_last_effective_superadmin_cannot_be_disabled_via_api(): void
    {
        $adminA = User::factory()->superAdmin()->create(['password' => 'Secret123']);
        $adminB = User::factory()->superAdmin()->create(['password' => 'Secret123']);

        // 两个有效超管：禁用一个允许
        $this->actingAs($adminA)
            ->postJson("/api/users/{$adminB->id}/disable")
            ->assertOk();

        // 剩余最后一个：禁用被拒绝（LAST_SUPER_ADMIN）
        $this->actingAs($adminA)
            ->postJson("/api/users/{$adminA->id}/disable")
            ->assertUnprocessable(); // 自己禁用自己在先

        $another = User::factory()->superAdmin()->create(['password' => 'Secret123']);
        // adminB 已被禁用 → adminA/another 为仅剩两个有效超管；禁用 another 后 adminA 成最后一个
        $this->actingAs($adminA)->postJson("/api/users/{$another->id}/disable")->assertOk();
        $this->actingAs($adminA)
            ->postJson("/api/users/{$adminA->id}/disable")
            ->assertUnprocessable();
    }

    public function test_guard_rejects_disabling_the_final_superadmin_but_allows_when_others_exist(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $guard = app(SuperAdminGuard::class);

        // 唯一超管：保护触发
        try {
            $guard->protect($admin, '禁用', fn () => $admin->update(['is_disabled' => true]));
            $this->fail('最后超管禁用必须被拒绝');
        } catch (\App\Exceptions\DomainConflictException $e) {
            $this->assertSame('LAST_SUPER_ADMIN', $e->errorCode);
            $this->assertSame(422, $e->status);
        }
        $this->assertFalse($admin->refresh()->is_disabled);

        // 新增一个有效超管后放行
        User::factory()->superAdmin()->create();
        $guard->protect($admin, '禁用', fn () => $admin->update(['is_disabled' => true]));
        $this->assertTrue($admin->refresh()->is_disabled);
    }

    public function test_advisory_lock_serializes_concurrent_last_superadmin_revocations(): void
    {
        $config = config('database.connections.pgsql');
        $make = fn () => new PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $lockKey = 'ipms:test-superadmin-guard';

        $connA = $make();
        $connA->beginTransaction();
        $connA->exec("SELECT pg_advisory_xact_lock(hashtextextended('{$lockKey}', 0))");

        // 连接 B 在 A 持锁期间取锁必须等待/超时，证明互斥
        $connB = $make();
        $connB->exec('SET lock_timeout = 300');
        $connB->beginTransaction();
        $blocked = false;
        try {
            $connB->exec("SELECT pg_advisory_xact_lock(hashtextextended('{$lockKey}', 0))");
        } catch (PDOException) {
            $blocked = true;
        }
        $this->assertTrue($blocked, '并发取锁必须互斥阻塞');

        // 锁超时使 B 事务进入中止态，回滚后重开
        $connB->rollBack();
        $connA->commit();
        $connB->beginTransaction();
        $connB->exec('SET lock_timeout = 3000');
        $connB->exec("SELECT pg_advisory_xact_lock(hashtextextended('{$lockKey}', 0))");
        $connB->commit();
        $this->assertTrue(true, 'A 提交后 B 可继续取锁');
    }
}
