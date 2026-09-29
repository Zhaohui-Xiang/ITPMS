<?php

namespace App\Services\Permissions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 会话撤销（v1.8 §2.10）：database 驱动可按用户删除；
 * file/redis 驱动无法按用户定位会话 —— 尽力而为并依赖 must_change_password
 * 与请求级 is_disabled/is_active 校验兜底。
 */
final class SessionRevoker
{
    public static function revokeAll(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }
}
