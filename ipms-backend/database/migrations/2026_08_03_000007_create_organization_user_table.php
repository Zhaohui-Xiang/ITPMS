<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('organization_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('role_in_org', 50)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->timestampTz('assigned_at')->default(DB::raw('NOW()'));
        });
        Schema::table('organization_user', function (Blueprint $t) {
            $t->unique(['user_id', 'organization_id'], 'ux_organization_user');
            $t->index('organization_id', 'ix_organization_user_org');
        });
    }
    public function down(): void { Schema::dropIfExists('organization_user'); }
};
