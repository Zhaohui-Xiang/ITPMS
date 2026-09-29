<?php

namespace Tests\Feature\Services;

use App\Exceptions\DomainConflictException;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Permissions\AuthorizationDigest;
use App\Services\Permissions\PermissionCache;
use App\Services\Permissions\PermissionEpoch;
use App\Services\Permissions\PermissionResolver;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    private PermissionResolver $resolver;
    private PermissionCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow();
        PermissionEpoch::flushMemo();
        AuthorizationDigest::flushMemo();
        config()->set('authorization.dual_source', true);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->resolver = app(PermissionResolver::class);
        $this->cache = app(PermissionCache::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        AuthorizationDigest::flushMemo();
        PermissionEpoch::flushMemo();
        parent::tearDown();
    }

    private function rule(string $subjectKind, int $subjectId, string $code, string $effect, ?string $expiresAt = null): void
    {
        DB::table('permission_rules')->insert([
            'permission_id' => Permission::query()->where('code', $code)->value('id'),
            $subjectKind => $subjectId,
            'effect' => $effect,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function freshUser(User $user): User
    {
        PermissionEpoch::flushMemo();
        return $user->refresh();
    }

    /**
     * 三维度真值表（2.2）：deny 优先于 allow，无规则回退内置默认。
     */
    public static function truthTable(): iterable
    {
        // [场景, 账户规则, 角色规则, 组织规则, 角色默认具备, 期望]
        yield '无规则-角色默认具备-allow' => [null, null, null, true, true];
        yield '无规则-角色默认不具备-deny' => [null, null, null, false, false];
        yield '组织deny覆盖角色默认allow' => [null, null, 'deny', true, false];
        yield '组织allow覆盖角色默认deny' => [null, null, 'allow', false, true];
        yield '账户deny+角色allow-deny优先' => ['deny', 'allow', null, true, false];
        yield '角色deny+组织allow-deny优先' => [null, 'deny', 'allow', true, false];
        yield '账户allow-直授' => ['allow', null, null, false, true];
        yield '三主体全allow-allow' => ['allow', 'allow', 'allow', false, true];
    }

    #[DataProvider('truthTable')]
    public function test_permission_truth_table(
        ?string $userRule,
        ?string $roleRule,
        ?string $orgRule,
        bool $roleDefault,
        bool $expected,
    ): void {
        // requester 默认无 task.view；it_member 默认有 task.view
        $user = $roleDefault
            ? User::factory()->withRole('it_member')->create()
            : User::factory()->withRole('requester')->create();
        $code = 'task.view';

        if ($roleRule !== null) {
            $roleId = $user->roles()->value('roles.id');
            $this->rule('role_id', $roleId, $code, $roleRule);
        }
        if ($orgRule !== null) {
            $org = Organization::query()->create([
                'name' => 'T组织', 'org_type' => $user->user_type, 'is_active' => true,
            ]);
            $user->organizations()->attach($org->id, [
                'role_in_org' => 'member', 'is_primary' => true, 'assigned_at' => now(),
            ]);
            $this->rule('organization_id', $org->id, $code, $orgRule);
        }
        if ($userRule !== null) {
            $this->rule('user_id', $user->id, $code, $userRule);
        }

        $this->assertSame($expected, $this->resolver->allows($this->freshUser($user), $code));
    }

    public function test_expired_rules_are_ignored_with_controllable_clock(): void
    {
        $user = User::factory()->withRole('requester')->create();
        $code = 'task.view';

        $this->rule('user_id', $user->id, $code, 'allow', now()->addHour()->toDateTimeString());
        $this->assertTrue($this->resolver->allows($this->freshUser($user), $code), '到期前应生效');

        // 恰好到期瞬间视为过期
        $this->rule('user_id', $user->id, 'task.create', 'allow', now()->toDateTimeString());
        $this->assertFalse($this->resolver->allows($this->freshUser($user), 'task.create'), '到期瞬间即失效');

        // 时钟越过到期时间
        Carbon::setTestNow(now()->addHours(2));
        PermissionEpoch::flushMemo();
        Cache::flush();
        $this->assertFalse(
            $this->resolver->allows($this->freshUser($user), $code),
            '到期后规则失效，回退内置默认',
        );
    }

    public function test_ttl_is_truncated_to_nearest_rule_expiry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 12:00:00'));
        $cache = app(PermissionCache::class);
        $this->assertSame(300, $cache->ttl(null));
        $this->assertSame(60, $cache->ttl(now()->addSeconds(60)));
        $this->assertSame(300, $cache->ttl(now()->addHour()));
        $this->assertSame(1, $cache->ttl(now()->subMinute()), '已过期也不应产生非正 TTL');
    }

    public function test_ab_builds_never_share_cache_entries(): void
    {
        $user = User::factory()->withRole('requester')->create();
        $code = 'task.view';
        $argsHash = hash('sha256', $code);
        $epoch = app(PermissionEpoch::class)->readPrimary();

        // A 构建计算并缓存 false（同 epoch）
        AuthorizationDigest::fake('build-a');
        $this->assertFalse($this->resolver->allows($user, $code));
        $keyA = $this->cache->key('permission', 'user:'.$user->id, $argsHash, $epoch);
        $this->assertFalse(Cache::get($keyA), 'A 命名空间应写入 false');

        // 切到 B 构建：同 epoch 也不得读 A 的缓存，必须重新计算
        AuthorizationDigest::fake('build-b');
        $keyB = $this->cache->key('permission', 'user:'.$user->id, $argsHash, $epoch);
        $this->assertNotSame($keyA, $keyB, '摘要必须进入缓存键');
        $this->assertNull(Cache::get($keyB), 'B 命名空间初始为空');
        $computedB = $this->cache->remember('permission', 'user:'.$user->id, $argsHash, fn () => true);
        $this->assertTrue($computedB, 'B 构建重新计算，不命中 A 的 false');
        $this->assertTrue(Cache::get($keyB));

        // B 失败回滚 A：B 未写入时回滚，A 仍读到自己命名空间的值
        AuthorizationDigest::fake('build-a');
        $this->assertFalse(
            $this->cache->remember('permission', 'user:'.$user->id, $argsHash, fn () => true),
            'B→A 回滚读取 A 自己的缓存，零交叉命中',
        );
    }

    public function test_dual_read_protocol_retries_and_fails_closed(): void
    {
        $user = User::factory()->withRole('requester')->create();

        // compute 每次都推高 epoch（模拟并发规则写入）→ 双读永不稳定 → 有界重试后 fail closed
        $calls = 0;
        try {
            $this->cache->remember('permission', 'user:'.$user->id, 'unstable', function () use (&$calls, $user) {
                $calls++;
                $id = DB::table('permission_rules')->insertGetId([
                    'permission_id' => Permission::query()->where('code', 'task.view')->value('id'),
                    'user_id' => $user->id,
                    'effect' => 'allow',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('permission_rules')->where('id', $id)->delete();
                return true;
            });
            $this->fail('epoch 不稳定时必须 fail closed');
        } catch (DomainConflictException $e) {
            $this->assertSame('AUTH_STATE_STALE', $e->errorCode);
            $this->assertSame(503, $e->status);
        }
        $this->assertSame(3, $calls, '有界重试 3 次');
    }

    public function test_subject_delete_cascades_rules_and_reverses_authorization(): void
    {
        $user = User::factory()->withRole('requester')->create();
        $org = Organization::query()->create([
            'name' => 'T组织', 'org_type' => 3, 'is_active' => true,
        ]);
        $user->organizations()->attach($org->id, [
            'role_in_org' => 'member', 'is_primary' => true, 'assigned_at' => now(),
        ]);
        $this->rule('organization_id', $org->id, 'task.view', 'allow');

        $this->assertTrue($this->resolver->allows($this->freshUser($user), 'task.view'));

        // 删除组织 → 主体级联删规则 → 回退默认
        $org->delete();
        PermissionEpoch::flushMemo();
        $this->assertFalse($this->resolver->allows($this->freshUser($user), 'task.view'));
        $this->assertDatabaseCount('permission_rules', 0);
    }

    public function test_only_effective_super_admin_bypasses_resolution(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->assertTrue($this->resolver->allows($admin, 'any.nonexistent.permission'));

        $admin->forceFill(['is_disabled' => true])->save();
        PermissionEpoch::flushMemo();
        $this->assertFalse(
            $this->resolver->allows($admin->refresh(), 'any.nonexistent.permission'),
            '被禁用超管不再绕过规则解析',
        );
    }

    public function test_inactive_organization_contributes_no_subject_rules(): void
    {
        $user = User::factory()->withRole('requester')->create();
        $org = Organization::query()->create([
            'name' => '停用组织', 'org_type' => 3, 'is_active' => false,
        ]);
        $user->organizations()->attach($org->id, [
            'role_in_org' => 'member', 'is_primary' => true, 'assigned_at' => now(),
        ]);
        $this->rule('organization_id', $org->id, 'task.view', 'allow');

        $this->assertFalse(
            $this->resolver->allows($this->freshUser($user), 'task.view'),
            'inactive 组织不贡献主体规则',
        );
    }
}
