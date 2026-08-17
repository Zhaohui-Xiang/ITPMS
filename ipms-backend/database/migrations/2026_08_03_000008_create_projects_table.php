<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->smallInteger('system_type');
            $t->text('description')->nullable();
            $t->smallInteger('status')->default(1);
            $t->foreignId('manager_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('supplier_org_id')->nullable()->constrained('organizations')->nullOnDelete();
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
            $t->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE projects ADD CONSTRAINT chk_projects_system_type CHECK (system_type IN (1,2))');
        DB::statement('ALTER TABLE projects ADD CONSTRAINT chk_projects_status CHECK (status IN (1,2,3))');
        Schema::table('projects', function (Blueprint $t) {
            $t->unique('name', 'ux_projects_name');
            $t->index('status', 'ix_projects_status');
            $t->index('manager_id', 'ix_projects_manager');
            $t->index('supplier_org_id', 'ix_projects_supplier_org');
        });
    }
    public function down(): void { Schema::dropIfExists('projects'); }
};
