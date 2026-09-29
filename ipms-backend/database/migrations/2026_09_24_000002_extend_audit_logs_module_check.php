<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 组织模块独立编码 8（此前组织操作错挂 7=系统与发布）
        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT chk_audit_logs_module');
        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT chk_audit_logs_module CHECK (module BETWEEN 1 AND 8)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT chk_audit_logs_module');
        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT chk_audit_logs_module CHECK (module BETWEEN 1 AND 7)');
    }
};
