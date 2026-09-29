<?php

namespace App\Services\Permissions;

use App\Exceptions\DomainConflictException;
use App\Support\Deployment;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * 权限缓存（v1.8 §2.6）：
 * - 键：perm:{deployment_uuid}:{accepted_auth_digest}:{epoch}:{decision_kind}:{subject}:{arguments_hash}
 * - TTL = min(300s, 最近 expires_at − now)
 * - 双读一致性协议：读 e1 → 按 e1 查缓存 → 未命中主库计算 → 再读 e2 → e1=e2 才写入并返回；
 *   缓存命中返回前同样校验 epoch 未变；有界重试后仍不稳定则 fail closed（AUTH_STATE_STALE，不写缓存）。
 */
final class PermissionCache
{
    private const BASE_TTL_SECONDS = 300;
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly PermissionEpoch $epoch,
    ) {}

    public function key(string $decisionKind, string $subject, string $argumentsHash, int $epoch): string
    {
        return sprintf(
            'perm:%s:%s:%d:%s:%s:%s',
            Deployment::uuid(),
            AuthorizationDigest::current(),
            $epoch,
            $decisionKind,
            $subject,
            $argumentsHash,
        );
    }

    public function ttl(?CarbonInterface $nearestExpiry): int
    {
        $ttl = self::BASE_TTL_SECONDS;
        if ($nearestExpiry !== null) {
            $ttl = min($ttl, max(1, (int) now()->diffInSeconds($nearestExpiry, false)));
        }
        return $ttl;
    }

    /**
     * @throws DomainConflictException 有界重试后 epoch 仍不稳定（fail closed）
     */
    public function remember(
        string $decisionKind,
        string $subject,
        string $argumentsHash,
        Closure $compute,
        ?CarbonInterface $nearestExpiry = null,
    ): mixed {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $e1 = $this->epoch->readPrimary();
            $cached = Cache::get($this->key($decisionKind, $subject, $argumentsHash, $e1));
            if ($cached !== null && $this->epoch->readPrimary() === $e1) {
                return $cached;
            }

            $value = $compute();
            $e2 = $this->epoch->readPrimary();
            if ($e1 === $e2) {
                Cache::put(
                    $this->key($decisionKind, $subject, $argumentsHash, $e1),
                    $value,
                    $this->ttl($nearestExpiry),
                );
                return $value;
            }
        }

        throw new DomainConflictException(
            'AUTH_STATE_STALE',
            503,
            [],
            '权限状态读取不稳定，请重试',
        );
    }

    /** 测试用 */
    public function read(string $decisionKind, string $subject, string $argumentsHash, int $epoch): mixed
    {
        return Cache::get($this->key($decisionKind, $subject, $argumentsHash, $epoch));
    }
}
