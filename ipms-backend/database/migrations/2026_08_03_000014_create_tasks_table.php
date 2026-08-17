<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requirement_id')->constrained('requirements')->cascadeOnDelete();
            $t->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->smallInteger('priority')->default(3);
            $t->smallInteger('status')->default(1);
            $t->date('due_date');
            $t->smallInteger('remind_days_before')->default(1);
            $t->decimal('estimated_hours', 8, 2)->nullable();
            $t->decimal('actual_hours', 8, 2)->nullable();
            $t->text('suspend_reason')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
            $t->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('last_reminded_at')->nullable();
        });
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT chk_tasks_priority CHECK (priority IN (1,2,3,4))');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT chk_tasks_status CHECK (status IN (1,2,3,4))');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT chk_tasks_remind_days CHECK (remind_days_before >= 0)');
        Schema::table('tasks', function (Blueprint $t) {
            $t->index('requirement_id', 'ix_tasks_requirement');
            $t->index('project_id', 'ix_tasks_project');
            $t->index('assignee_id', 'ix_tasks_assignee');
            $t->index('status', 'ix_tasks_status');
            $t->index('due_date', 'ix_tasks_due_date');
            $t->index(['status','due_date'], 'ix_tasks_due_remind');
            $t->index(['assignee_id','status'], 'ix_tasks_assignee_status');
        });
    }
    public function down(): void { Schema::dropIfExists('tasks'); }
};
