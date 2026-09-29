<?php

namespace App\Console\Commands;

use App\Services\Permissions\SuperAdminRecovery;
use Illuminate\Console\Command;

/**
 * 超管离线恢复（v1.8 §2.10）：
 *   ipms:recover-superadmin --issue --target=<userId>   签发一次性凭证（仅打印一次）
 *   ipms:recover-superadmin --target=<userId> --credential=<凭证>   消费凭证执行恢复
 */
class RecoverSuperAdmin extends Command
{
    protected $signature = 'ipms:recover-superadmin
        {--target= : 目标用户 ID}
        {--issue : 签发恢复凭证}
        {--credential= : 恢复凭证}
        {--operator=console : 操作者标识（入审计）}';

    protected $description = 'Recover super admin access when no effective super admin remains (maintenance window only)';

    public function handle(SuperAdminRecovery $recovery): int
    {
        $target = $this->option('target');
        if (! is_numeric($target)) {
            $this->error('必须指定 --target=<用户ID>');
            return self::INVALID;
        }

        try {
            if ($this->option('issue')) {
                $credential = $recovery->issue((int) $target, (string) $this->option('operator'));
                $this->info('恢复凭证已签发（15 分钟内有效，仅此一次显示）：');
                $this->line($credential);
                return self::SUCCESS;
            }

            $credential = $this->option('credential');
            if (! is_string($credential) || $credential === '') {
                $this->error('必须提供 --credential=<恢复凭证>，或使用 --issue 签发');
                return self::INVALID;
            }

            $user = $recovery->recover((int) $target, $credential);
            $this->info("已恢复 {$user->display_name}（{$user->username}）的超级管理员权限，已强制要求下次登录修改密码。");
            return self::SUCCESS;
        } catch (\App\Exceptions\DomainConflictException $e) {
            $this->error("[{$e->errorCode}] {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
