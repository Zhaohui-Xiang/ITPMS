<?php

namespace App\Services\Permissions;

use Illuminate\Support\Facades\DB;

/**
 * 权限 epoch（v1.8 §2.6）：全局单调计数器，由数据库触发器递增；
 * 读取必须走主库连接（Phase A 单连接即主库）。
 */
final class PermissionEpoch
{
    private static ?int $memo = null;

    public function current(): int
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        return $this->readPrimary();
    }

    /** 双读协议专用：绕过进程内记忆强制读主库当前值 */
    public function readPrimary(): int
    {
        return (int) DB::table('permission_epoch')->where('id', true)->value('value');
    }

    /** 测试用：重置进程内记忆 */
    public static function flushMemo(): void
    {
        self::$memo = null;
    }
}
