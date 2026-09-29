<?php

namespace App\Services\Permissions;

use App\Enums\UserType;
use App\Exceptions\DomainConflictException;
use App\Http\Middleware\AuditLogger;
use App\Models\Role;
use App\Models\User;
use App\Support\Deployment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 超管离线恢复（v1.8 §2.10 恢复凭证契约）：
 * - 仅当有效超管数 = 0 且系统处于维护停机状态才可签发/消费；
 * - 凭证绑定 deployment_uuid + target_user_id + nonce，15 分钟有效；
 * - 只持久化凭证哈希；同一 advisory-lock 事务内原子标记 used_at；
 * - 审计脱敏（不记录凭证），执行后撤会话（尽力）+ 强制改密。
 */
final class SuperAdminRecovery
{
    private const LOCK_KEY = 'ipms:superadmin-recovery';
    private const CREDENTIAL_TTL_MINUTES = 15;

    public function __construct(
        private readonly SuperAdminGuard $guard,
        private readonly SystemDownProbe $probe,
    ) {}

    /** 签发恢复凭证，返回一次性明文凭证（仅此一次） */
    public function issue(int $targetUserId, string $operator): string
    {
        $this->assertPreconditions();

        $target = User::query()->findOrFail($targetUserId);
        if ($target->user_type !== UserType::INTERNAL->value) {
            throw new DomainConflictException(
                'RECOVERY_TARGET_NOT_INTERNAL',
                422,
                [],
                '恢复目标必须是内部用户（super_admin 角色仅绑定内部类型）。',
            );
        }

        $secret = Str::random(48);
        $nonce = Str::random(24);
        DB::table('superadmin_recovery_credentials')->insert([
            'target_user_id' => $target->id,
            'credential_hash' => hash('sha256', $secret),
            'nonce' => $nonce,
            'deployment_uuid' => Deployment::uuid(),
            'expires_at' => now()->addMinutes(self::CREDENTIAL_TTL_MINUTES),
            'operator' => $operator,
            'created_at' => now(),
        ]);

        return $secret.'.'.$nonce;
    }

    /** 消费凭证执行恢复（锁内原子） */
    public function recover(int $targetUserId, string $credential): User
    {
        return DB::transaction(function () use ($targetUserId, $credential) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::LOCK_KEY]);
            $this->assertPreconditions();

            $parts = explode('.', $credential, 2);
            if (count($parts) !== 2) {
                throw $this->invalidCredential();
            }
            [$secret, $nonce] = $parts;

            $row = DB::table('superadmin_recovery_credentials')
                ->where('target_user_id', $targetUserId)
                ->where('nonce', $nonce)
                ->lockForUpdate()
                ->first();
            if ($row === null || ! hash_equals($row->credential_hash, hash('sha256', $secret))) {
                throw $this->invalidCredential();
            }
            if ($row->used_at !== null) {
                throw new DomainConflictException('RECOVERY_CREDENTIAL_REPLAYED', 422, [], '凭证已被使用。');
            }
            if (Carbon::parse($row->expires_at)->isPast()) {
                throw new DomainConflictException('RECOVERY_CREDENTIAL_EXPIRED', 422, [], '凭证已过期。');
            }
            if ($row->deployment_uuid !== Deployment::uuid()) {
                throw new DomainConflictException('RECOVERY_CREDENTIAL_STALE', 422, [], '凭证已随部署失效。');
            }

            // 原子消费
            $consumed = DB::table('superadmin_recovery_credentials')
                ->where('id', $row->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
            if ($consumed !== 1) {
                throw new DomainConflictException('RECOVERY_CREDENTIAL_REPLAYED', 422, [], '凭证已被使用。');
            }

            $target = User::query()->lockForUpdate()->findOrFail($targetUserId);
            $before = [
                'roles' => $target->roles()->pluck('code')->all(),
                'is_active' => (bool) $target->is_active,
                'is_disabled' => (bool) $target->is_disabled,
            ];

            $superAdminRole = Role::query()->where('code', 'super_admin')->firstOrFail();
            $target->roles()->syncWithoutDetaching([
                $superAdminRole->id => ['assigned_by_id' => null, 'assigned_at' => now()],
            ]);
            $target->forceFill([
                'is_active' => true,
                'is_disabled' => false,
                'must_change_password' => true,
            ])->save();

            SessionRevoker::revokeAll($target);

            $operator = $row->operator;
            AuditLogger::log($target->id, [
                'user_name' => $target->username,
                'user_display_name' => $target->display_name,
                'user_type' => $target->user_type,
                'module' => 'user',
                'action_type' => 'update',
                'target_type' => 'user',
                'target_id' => $target->id,
                'target_name' => $target->display_name,
                'detail' => [
                    'action' => 'superadmin_recovery',
                    'operator' => $operator,
                    'target' => $target->id,
                    'before' => $before,
                    'after' => ['roles' => [...$before['roles'], 'super_admin'], 'is_active' => true, 'is_disabled' => false],
                    'build_id' => AuthorizationDigest::current(),
                ],
            ]);

            return $target->refresh();
        });
    }

    private function assertPreconditions(): void
    {
        if ($this->guard->effectiveSuperAdminCount() > 0) {
            throw new DomainConflictException(
                'RECOVERY_NOT_NEEDED',
                422,
                [],
                '系统仍存在有效超级管理员，禁止使用恢复命令。',
            );
        }
        if (! $this->probe->isDown()) {
            throw new DomainConflictException(
                'RECOVERY_REQUIRES_MAINTENANCE',
                422,
                [],
                '恢复命令仅允许在维护停机窗口执行（维护页开启且 FPM/queue/scheduler 已停止）。',
            );
        }
    }

    private function invalidCredential(): DomainConflictException
    {
        return new DomainConflictException('RECOVERY_CREDENTIAL_INVALID', 422, [], '恢复凭证无效。');
    }
}
