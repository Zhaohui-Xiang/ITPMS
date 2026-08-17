<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_version_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_version_id')
                ->constrained('project_versions')
                ->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->smallInteger('from_status')->nullable();
            $table->smallInteger('to_status')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->default(DB::raw('NOW()'));

            $table->index(
                ['project_version_id', 'created_at'],
                'ix_project_version_histories_version_created',
            );
            $table->index('event_type', 'ix_project_version_histories_event_type');
        });

        DB::statement(
            'ALTER TABLE project_version_histories ADD CONSTRAINT chk_project_version_histories_from_status CHECK (from_status IS NULL OR from_status IN (1,2,3,4,5,6,7))'
        );
        DB::statement(
            'ALTER TABLE project_version_histories ADD CONSTRAINT chk_project_version_histories_to_status CHECK (to_status IS NULL OR to_status IN (1,2,3,4,5,6,7))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('project_version_histories');
    }
};
