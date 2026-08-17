<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_version_release_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_version_id')
                ->unique('ux_project_version_release_snapshots_version')
                ->constrained('project_versions')
                ->restrictOnDelete();
            $table->jsonb('requirement_scope');
            $table->unsignedInteger('task_count');
            $table->unsignedInteger('defect_count');
            $table->jsonb('gate_result');
            $table->text('release_notes')->nullable();
            $table->boolean('is_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('released_by_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_at');
        });

        DB::statement(
            'ALTER TABLE project_version_release_snapshots ADD CONSTRAINT chk_project_version_release_snapshots_override CHECK (is_override OR override_reason IS NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('project_version_release_snapshots');
    }
};
