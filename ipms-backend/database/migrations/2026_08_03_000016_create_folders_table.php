<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('folders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('folders')->nullOnDelete();
            $t->string('name', 100);
            $t->smallInteger('level')->default(0);
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
            $t->softDeletes('deleted_at');
            $t->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE folders ADD CONSTRAINT chk_folders_level CHECK (level BETWEEN 0 AND 2)');
        Schema::table('folders', function (Blueprint $t) {
            $t->index('project_id', 'ix_folders_project');
            $t->index('parent_id', 'ix_folders_parent');
            $t->index(['project_id','parent_id'], 'ix_folders_project_parent');
        });
    }
    public function down(): void { Schema::dropIfExists('folders'); }
};
