<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->smallInteger('status')->default(1);
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_release_date')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->foreignId('released_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('release_notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->default(DB::raw('NOW()'));
            $table->timestampTz('updated_at')->default(DB::raw('NOW()'));

            $table->unique(['project_id', 'code'], 'ux_project_versions_project_code');
            $table->unique(['id', 'project_id'], 'ux_project_versions_id_project');
            $table->index('status', 'ix_project_versions_status');
            $table->index('owner_id', 'ix_project_versions_owner');
            $table->index('planned_release_date', 'ix_project_versions_release_date');
        });

        DB::statement(
            'ALTER TABLE project_versions ADD CONSTRAINT chk_project_versions_status CHECK (status IN (1,2,3,4,5,6,7))'
        );
        DB::statement(
            'ALTER TABLE project_versions ADD CONSTRAINT chk_project_versions_lock_version CHECK (lock_version >= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('project_versions');
    }
};
