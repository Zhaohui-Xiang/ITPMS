<?php

namespace App\Console\Commands;

use App\Services\Permissions\SuperAdminGuard;
use App\Services\Permissions\TypeMismatchScanner;
use App\Support\AuthorizationRegistry;
use Illuminate\Console\Command;

/**
 * 部署前置断言（v1.8 §2.10）：有效超管 ≥ 1、类型错配为零、注册表完整。
 * 任一失败即非零退出，供部署脚本中止发布。
 */
class PermissionPreflight extends Command
{
    protected $signature = 'ipms:perm-preflight';
    protected $description = 'Assert superadmin presence, zero type mismatches and registry completeness before deploy';

    public function handle(SuperAdminGuard $guard, TypeMismatchScanner $scanner): int
    {
        $failed = false;

        $superAdmins = $guard->effectiveSuperAdminCount();
        if ($superAdmins >= 1) {
            $this->info("✓ 有效超管 {$superAdmins} 个");
        } else {
            $this->error('✗ 有效超管为 0，禁止部署');
            $failed = true;
        }

        $roleMismatches = $scanner->roleUserMismatches();
        $orgMismatches = $scanner->organizationUserMismatches();
        $mismatches = $roleMismatches->count() + $orgMismatches->count();
        if ($mismatches === 0) {
            $this->info('✓ 主体类型错配为零');
        } else {
            $this->error("✗ 主体类型错配 {$mismatches} 条（role_user {$roleMismatches->count()} / organization_user {$orgMismatches->count()}）");
            $failed = true;
        }

        $missing = AuthorizationRegistry::missingEntries();
        $orphans = AuthorizationRegistry::orphanEntries();
        if ($missing === [] && $orphans === []) {
            $this->info('✓ 授权注册表完整（路由与条目一一对应）');
        } else {
            foreach ($missing as $key) {
                $this->error("✗ 注册表缺少路由条目：{$key}");
            }
            foreach ($orphans as $key) {
                $this->error("✗ 注册表条目无对应路由：{$key}");
            }
            $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
