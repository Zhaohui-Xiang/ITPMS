<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('project_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('role_in_project', 20);
            $t->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('assigned_at')->default(DB::raw('NOW()'));
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
        });
        DB::statement("ALTER TABLE project_members ADD CONSTRAINT chk_project_members_role CHECK (role_in_project IN ('pm','member'))");
        Schema::table('project_members', function (Blueprint $t) {
            $t->unique(['user_id', 'project_id'], 'ux_project_members_uid_pid');
            $t->index('project_id', 'ix_project_members_project');
            $t->index('user_id', 'ix_project_members_user');
        });
    }
    public function down(): void { Schema::dropIfExists('project_members'); }
};
