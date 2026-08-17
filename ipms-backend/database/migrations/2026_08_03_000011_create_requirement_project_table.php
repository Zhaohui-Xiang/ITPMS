<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('requirement_project', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requirement_id')->constrained('requirements')->cascadeOnDelete();
            $t->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
        });
        Schema::table('requirement_project', function (Blueprint $t) {
            $t->unique(['requirement_id','project_id'], 'ux_requirement_project');
            $t->index('project_id', 'ix_requirement_project_project');
            $t->index('requirement_id', 'ix_requirement_project_requirement');
        });
    }
    public function down(): void { Schema::dropIfExists('requirement_project'); }
};
