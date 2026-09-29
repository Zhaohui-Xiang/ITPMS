<?php

namespace App\Services\Permissions;

/**
 * 当前进程构建的授权摘要（v1.8 §2.8 缓存键组成之一）。
 * Phase A 占位实现：注册表文件内容哈希。Phase B 起接入 resource_auth_builds 状态机。
 */
final class AuthorizationDigest
{
    private static ?string $fake = null;
    private static ?string $memo = null;

    public static function current(): string
    {
        if (self::$fake !== null) {
            return self::$fake;
        }
        if (self::$memo !== null) {
            return self::$memo;
        }
        $file = config_path('authorization.php');
        return self::$memo = substr(hash_file('sha256', $file), 0, 16);
    }

    /** 测试用：模拟 A/B 构建 */
    public static function fake(?string $digest): void
    {
        self::$fake = $digest;
    }

    public static function flushMemo(): void
    {
        self::$memo = null;
        self::$fake = null;
    }
}
