<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $t->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $t->string('effect', 8);
            $t->text('reason')->nullable();
            $t->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
        });
        DB::statement('ALTER TABLE permission_rules ADD CONSTRAINT chk_permission_rules_subject_xor CHECK (num_nonnulls(user_id, role_id, organization_id) = 1)');
        DB::statement("ALTER TABLE permission_rules ADD CONSTRAINT chk_permission_rules_effect CHECK (effect IN ('allow','deny'))");
        DB::statement('CREATE UNIQUE INDEX ux_permission_rules_user ON permission_rules (permission_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ux_permission_rules_role ON permission_rules (permission_id, role_id) WHERE role_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ux_permission_rules_org ON permission_rules (permission_id, organization_id) WHERE organization_id IS NOT NULL');
        DB::statement('CREATE INDEX ix_permission_rules_expires ON permission_rules (expires_at) WHERE expires_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_rules');
    }
};
