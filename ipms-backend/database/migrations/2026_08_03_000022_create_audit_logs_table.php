<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id('id', 'bigserial');
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('user_name', 150);
            $t->string('user_display_name', 100)->default('');
            $t->smallInteger('user_type');
            $t->smallInteger('module');
            $t->smallInteger('action_type');
            $t->string('target_type', 50);
            $t->string('target_id', 50)->nullable();
            $t->string('target_name', 500)->nullable();
            $t->jsonb('detail')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
        });
        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT chk_audit_logs_user_type CHECK (user_type IN (1,2,3))');
        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT chk_audit_logs_module CHECK (module BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT chk_audit_logs_action CHECK (action_type BETWEEN 1 AND 10)');
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->index('created_at', 'ix_audit_logs_created_at');
            $t->index('user_id', 'ix_audit_logs_user');
            $t->index('module', 'ix_audit_logs_module');
            $t->index('action_type', 'ix_audit_logs_action');
            $t->index(['module','action_type'], 'ix_audit_logs_module_action');
            $t->index(['user_id','created_at'], 'ix_audit_logs_user_created');
            $t->index(['target_type','target_id'], 'ix_audit_logs_target');
        });
    }
    public function down(): void { Schema::dropIfExists('audit_logs'); }
};
