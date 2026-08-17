<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('requirements', function (Blueprint $t) {
            $t->id();
            $t->string('title', 200);
            $t->text('description');
            $t->smallInteger('priority');
            $t->smallInteger('requirement_type');
            $t->foreignId('submitter_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('submitted_at')->default(DB::raw('NOW()'));
            $t->date('expected_completion_date')->nullable();
            $t->smallInteger('status')->default(1);
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('review_comment')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->foreignId('dev_lead_id')->nullable()->constrained('users')->nullOnDelete();
            $t->integer('version')->default(1);
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
            $t->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::statement('ALTER TABLE requirements ADD CONSTRAINT chk_requirements_priority CHECK (priority IN (1,2,3,4))');
        DB::statement('ALTER TABLE requirements ADD CONSTRAINT chk_requirements_type CHECK (requirement_type IN (1,2,3,4,5))');
        DB::statement('ALTER TABLE requirements ADD CONSTRAINT chk_requirements_status CHECK (status IN (1,2,3,4,5,6,7))');
        DB::statement('ALTER TABLE requirements ADD CONSTRAINT chk_requirements_version CHECK (version >= 1)');
        Schema::table('requirements', function (Blueprint $t) {
            $t->index('status', 'ix_requirements_status');
            $t->index('submitter_id', 'ix_requirements_submitter');
            $t->index('priority', 'ix_requirements_priority');
            $t->index('submitted_at', 'ix_requirements_submitted_at');
            $t->index('expected_completion_date', 'ix_requirements_expected_date');
            $t->index(['status','priority'], 'ix_requirements_status_priority');
            $t->index('reviewer_id', 'ix_requirements_reviewer');
        });
    }
    public function down(): void { Schema::dropIfExists('requirements'); }
};
