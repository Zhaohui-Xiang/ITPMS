<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_permissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $t->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $t->string('resource', 32);
            $t->string('field', 64);
            $t->string('effect', 16);
            $t->text('reason')->nullable();
            $t->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
        });
        DB::statement('ALTER TABLE field_permissions ADD CONSTRAINT chk_field_permissions_subject_xor CHECK (num_nonnulls(user_id, role_id, organization_id) = 1)');
        DB::statement("ALTER TABLE field_permissions ADD CONSTRAINT chk_field_permissions_effect CHECK (effect IN ('editable','readonly','masked','hidden'))");
        DB::statement('CREATE UNIQUE INDEX ux_field_permissions_user ON field_permissions (resource, field, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ux_field_permissions_role ON field_permissions (resource, field, role_id) WHERE role_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ux_field_permissions_org ON field_permissions (resource, field, organization_id) WHERE organization_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('field_permissions');
    }
};
