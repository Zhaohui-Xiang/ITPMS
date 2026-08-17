<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('permission_role', function (Blueprint $t) {
            $t->id();
            $t->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
        });
        Schema::table('permission_role', function (Blueprint $t) {
            $t->unique(['role_id','permission_id'], 'ux_permission_role');
            $t->index('role_id', 'ix_permission_role_role');
            $t->index('permission_id', 'ix_permission_role_perm');
        });
    }
    public function down(): void { Schema::dropIfExists('permission_role'); }
};
