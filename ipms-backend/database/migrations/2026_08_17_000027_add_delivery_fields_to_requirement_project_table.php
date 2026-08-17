<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requirement_project', function (Blueprint $table) {
            $table->unsignedBigInteger('project_version_id')->nullable()->after('project_id');
            $table->smallInteger('delivery_status')->default(2)->after('project_version_id');
            $table->foreignId('version_assigned_by_id')
                ->nullable()
                ->after('delivery_status')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('version_assigned_at')->nullable()->after('version_assigned_by_id');

            $table->foreign(
                ['project_version_id', 'project_id'],
                'fk_requirement_project_version_project',
            )->references(['id', 'project_id'])
                ->on('project_versions')
                ->restrictOnDelete();
            $table->index(
                ['project_version_id', 'project_id'],
                'ix_requirement_project_version_project',
            );
            $table->index('delivery_status', 'ix_requirement_project_delivery_status');
        });

        DB::statement(
            'ALTER TABLE requirement_project ADD CONSTRAINT chk_requirement_project_delivery_status CHECK (delivery_status IN (2,3,4,5,6,7))'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE requirement_project DROP CONSTRAINT IF EXISTS chk_requirement_project_delivery_status'
        );

        Schema::table('requirement_project', function (Blueprint $table) {
            $table->dropForeign('fk_requirement_project_version_project');
            $table->dropForeign(['version_assigned_by_id']);
            $table->dropIndex('ix_requirement_project_version_project');
            $table->dropIndex('ix_requirement_project_delivery_status');
            $table->dropColumn([
                'project_version_id',
                'delivery_status',
                'version_assigned_by_id',
                'version_assigned_at',
            ]);
        });
    }
};
