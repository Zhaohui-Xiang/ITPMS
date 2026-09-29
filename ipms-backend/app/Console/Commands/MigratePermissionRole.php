<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * permission_role → permission_rules 单事实源迁移（v1.8 §2.12）。
 * 默认执行幂等回填并核对；--verify 只核对不写。
 * 生产切换必须按 2.12 停写顺序执行，本命令不做停写。
 */
class MigratePermissionRole extends Command
{
    protected $signature = 'ipms:perm-migrate {--verify : 只核对，不写入}';
    protected $description = 'Backfill permission_role into permission_rules (role-subject allow rules) and verify parity';

    public function handle(): int
    {
        if (! $this->option('verify')) {
            $written = $this->backfill();
            $this->info("回填完成：新增 {$written} 条角色规则。");
        }

        return $this->verify() ? self::SUCCESS : self::FAILURE;
    }

    private function backfill(): int
    {
        $rows = DB::table('permission_role')->get(['role_id', 'permission_id']);
        $written = 0;
        foreach ($rows as $row) {
            // 部分唯一索引保证幂等
            $affected = DB::table('permission_rules')->insertOrIgnore([
                'permission_id' => $row->permission_id,
                'role_id' => $row->role_id,
                'effect' => 'allow',
                'reason' => 'permission_role 停写回填',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $written += (int) $affected;
        }
        return $written;
    }

    private function verify(): bool
    {
        $legacy = DB::table('permission_role')->orderBy('role_id')->orderBy('permission_id')
            ->get(['role_id', 'permission_id']);
        $migrated = DB::table('permission_rules')
            ->whereNotNull('role_id')
            ->orderBy('role_id')->orderBy('permission_id')
            ->get(['role_id', 'permission_id']);

        $legacyHash = $this->hash($legacy);
        $migratedHash = $this->hash($migrated);

        $this->line(sprintf(
            'permission_role=%d 行（%s） / permission_rules(角色规则)=%d 行（%s）',
            $legacy->count(), substr($legacyHash, 0, 12),
            $migrated->count(), substr($migratedHash, 0, 12),
        ));

        if ($legacyHash !== $migratedHash) {
            $this->error('核对失败：两表角色权限映射不一致，禁止切换。');
            return false;
        }

        $this->info('核对一致：行数与内容哈希相同。');
        return true;
    }

    private function hash($rows): string
    {
        return hash('sha256', $rows->map(
            fn ($row) => $row->role_id.':'.$row->permission_id,
        )->implode('|'));
    }
}
