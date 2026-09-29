<?php

namespace App\Console\Commands;

use App\Services\Permissions\TypeMismatchScanner;
use Illuminate\Console\Command;

/**
 * 存量主体类型错配扫描（Phase A）：列出 role_user / organization_user 中
 * users.user_type 与 roles.user_type / organizations.org_type 不一致的绑定。
 * 只报告不修复；发现错配返回退出码 1。
 */
class ScanTypeMismatches extends Command
{
    protected $signature = 'ipms:perm-scan-types';
    protected $description = 'Scan role_user and organization_user for subject type mismatches (report only)';

    public function handle(TypeMismatchScanner $scanner): int
    {
        $roleMismatches = $scanner->roleUserMismatches();
        $orgMismatches = $scanner->organizationUserMismatches();

        $this->info('主体类型错配扫描报告');
        $this->line(sprintf('role_user 错配: %d 条', $roleMismatches->count()));
        foreach ($roleMismatches as $row) {
            $this->warn(sprintf(
                '  [role_user#%d] 用户 %s (type=%d) × 角色 %s (type=%d)',
                $row->id, $row->username, $row->user_type, $row->role_code, $row->role_type,
            ));
        }
        $this->line(sprintf('organization_user 错配: %d 条', $orgMismatches->count()));
        foreach ($orgMismatches as $row) {
            $this->warn(sprintf(
                '  [organization_user#%d] 用户 %s (type=%d) × 组织 %s (type=%d)',
                $row->id, $row->username, $row->user_type, $row->org_name, $row->org_type,
            ));
        }

        $total = $roleMismatches->count() + $orgMismatches->count();
        if ($total > 0) {
            $this->error("共 {$total} 条错配。请按 2.4 类型变更事务流程迁移后再继续。");
            return self::FAILURE;
        }

        $this->info('未发现类型错配。');
        return self::SUCCESS;
    }
}
