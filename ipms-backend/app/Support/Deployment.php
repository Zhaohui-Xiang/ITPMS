<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 部署 UUID（v1.8 §2.6）：权限缓存命名空间。
 * 数据库恢复/反向迁移/epoch 重建必须原子 rotate() 或清空全部权限缓存。
 */
final class Deployment
{
    private static ?string $memo = null;

    public static function uuid(): string
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        $row = DB::table('deployment_uuid')->where('id', true)->first();
        if ($row === null) {
            $uuid = (string) Str::uuid();
            DB::table('deployment_uuid')->insert([
                'id' => true, 'uuid' => $uuid, 'created_at' => now(),
            ]);
            return self::$memo = $uuid;
        }
        return self::$memo = $row->uuid;
    }

    /** 原子更换部署 UUID（epoch 重建/库回滚时调用） */
    public static function rotate(): string
    {
        $uuid = (string) Str::uuid();
        DB::table('deployment_uuid')->where('id', true)->update(['uuid' => $uuid]);
        self::$memo = $uuid;
        return $uuid;
    }

    /** 测试用：重置进程内记忆 */
    public static function flushMemo(): void
    {
        self::$memo = null;
    }
}
