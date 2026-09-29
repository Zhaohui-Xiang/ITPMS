<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Models\User;
use App\Services\Permissions\SuperAdminRecovery;
use App\Services\Permissions\SystemDownProbe;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SuperAdminRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private SuperAdminRecovery $recovery;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->app->instance(SystemDownProbe::class, new class extends SystemDownProbe {
            public function isDown(): bool
            {
                return true;
            }
        });
        $this->recovery = $this->app->make(SuperAdminRecovery::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function target(): User
    {
        return User::factory()->withRole('it_member')->create(['is_disabled' => true]);
    }

    public function test_issue_and_recover_happy_path(): void
    {
        $target = $this->target();

        $credential = $this->recovery->issue($target->id, 'ops-console');
        $this->assertStringContainsString('.', $credential);

        // 只持久化哈希
        $row = DB::table('superadmin_recovery_credentials')->first();
        $this->assertStringNotContainsString(explode('.', $credential)[0], json_encode($row));

        $recovered = $this->recovery->recover($target->id, $credential);
        $this->assertTrue($recovered->isEffectiveSuperAdmin());
        $this->assertTrue($recovered->must_change_password);

        // 审计脱敏：不包含凭证/原文
        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('superadmin_recovery', json_decode($audit->detail, true)['action'] ?? null);
        $this->assertStringNotContainsString($credential, (string) $audit->detail);
    }

    public function test_issue_rejected_when_superadmin_exists(): void
    {
        User::factory()->superAdmin()->create();
        try {
            $this->recovery->issue($this->target()->id, 'ops');
            $this->fail('有效超管存在时禁止签发');
        } catch (DomainConflictException $e) {
            $this->assertSame('RECOVERY_NOT_NEEDED', $e->errorCode);
        }
    }

    public function test_issue_rejected_when_system_online(): void
    {
        $this->app->instance(SystemDownProbe::class, new class extends SystemDownProbe {
            public function isDown(): bool
            {
                return false;
            }
        });
        $recovery = $this->app->make(SuperAdminRecovery::class);
        try {
            $recovery->issue($this->target()->id, 'ops');
            $this->fail('在线状态禁止签发');
        } catch (DomainConflictException $e) {
            $this->assertSame('RECOVERY_REQUIRES_MAINTENANCE', $e->errorCode);
        }
    }

    public function test_replay_is_rejected(): void
    {
        $target = $this->target();
        $credential = $this->recovery->issue($target->id, 'ops');
        $this->recovery->recover($target->id, $credential);

        try {
            $this->recovery->recover($target->id, $credential);
            $this->fail('重放必须被拒绝');
        } catch (DomainConflictException $e) {
            // 重放时目标已是有效超管 → 前置条件先拦截
            $this->assertSame('RECOVERY_NOT_NEEDED', $e->errorCode);
        }
    }

    public function test_expired_credential_is_rejected(): void
    {
        $target = $this->target();
        $credential = $this->recovery->issue($target->id, 'ops');
        Carbon::setTestNow(now()->addMinutes(16));

        try {
            $this->recovery->recover($target->id, $credential);
            $this->fail('过期凭证必须被拒绝');
        } catch (DomainConflictException $e) {
            $this->assertSame('RECOVERY_CREDENTIAL_EXPIRED', $e->errorCode);
        }
    }

    public function test_wrong_credential_is_rejected(): void
    {
        $target = $this->target();
        $this->recovery->issue($target->id, 'ops');

        try {
            $this->recovery->recover($target->id, 'wrong-secret.wrong-nonce');
            $this->fail('错误凭证必须被拒绝');
        } catch (DomainConflictException $e) {
            $this->assertSame('RECOVERY_CREDENTIAL_INVALID', $e->errorCode);
        }
    }

    public function test_non_internal_target_is_rejected(): void
    {
        $supplier = User::factory()->withRole('supplier_dev')->create(['is_disabled' => true]);
        try {
            $this->recovery->issue($supplier->id, 'ops');
            $this->fail('非内部用户不能作为恢复目标');
        } catch (DomainConflictException $e) {
            $this->assertSame('RECOVERY_TARGET_NOT_INTERNAL', $e->errorCode);
        }
    }
}
