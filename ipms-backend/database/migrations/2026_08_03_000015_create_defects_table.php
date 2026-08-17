<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('defects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requirement_id')->constrained('requirements')->cascadeOnDelete();
            $t->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $t->string('title', 200);
            $t->text('description');
            $t->smallInteger('severity');
            $t->smallInteger('defect_type');
            $t->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('discovered_at')->default(DB::raw('NOW()'));
            $t->smallInteger('discovery_phase');
            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->smallInteger('status')->default(1);
            $t->string('screenshot', 255)->nullable();
            $t->text('fix_description')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
            $t->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE defects ADD CONSTRAINT chk_defects_severity CHECK (severity IN (1,2,3,4))');
        DB::statement('ALTER TABLE defects ADD CONSTRAINT chk_defects_type CHECK (defect_type IN (1,2,3,4,5))');
        DB::statement('ALTER TABLE defects ADD CONSTRAINT chk_defects_status CHECK (status IN (1,2,3,4,5,6))');
        DB::statement('ALTER TABLE defects ADD CONSTRAINT chk_defects_phase CHECK (discovery_phase IN (1,2))');
        Schema::table('defects', function (Blueprint $t) {
            $t->index('requirement_id', 'ix_defects_requirement');
            $t->index('project_id', 'ix_defects_project');
            $t->index('status', 'ix_defects_status');
            $t->index('severity', 'ix_defects_severity');
            $t->index('assignee_id', 'ix_defects_assignee');
            $t->index(['status','severity'], 'ix_defects_status_severity');
            $t->index('reporter_id', 'ix_defects_reporter');
        });
    }
    public function down(): void { Schema::dropIfExists('defects'); }
};
