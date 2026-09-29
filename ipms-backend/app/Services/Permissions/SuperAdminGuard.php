<?php

namespace App\Services\Permissions;

use App\Exceptions\DomainConflictException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 最后超管保护（v1.8 §2.10）：禁用/降权/删除/角色解绑/is_active=false 等路径
 * 统一在同一 advisory 锁事务内重算有效超管数，归零即拒绝。
 */
final class SuperAdminGuard
{
    private const LOCK_KEY = 'ipms:superadmin-guard';

    public function effectiveSuperAdminCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->where('is_disabled', false)
            ->whereHas('roles', fn ($q) => $q->where('code', 'super_admin'))
            ->count();
    }

    /**
     * 在 advisory 锁事务内执行操作；目标为最后一个有效超管时拒绝。
     *
     * @template T
     * @param  callable(): T  $operation
     * @return T
     */
    public function protect(User $target, string $action, callable $operation): mixed
    {
        return DB::transaction(function () use ($target, $action, $operation) {
            $this->lock();
            $target->refresh();
            if ($target->isEffectiveSuperAdmin() && $this->effectiveSuperAdminCount() <= 1) {
                throw new DomainConflictException(
                    'LAST_SUPER_ADMIN',
                    422,
                    [],
                    "最后一个有效超级管理员不可{$action}。",
                );
            }
            return $operation();
        });
    }

    /** 获取锁（事务内有效） */
    public function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::LOCK_KEY]);
    }
}
