<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('requirement_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requirement_id')->constrained('requirements')->cascadeOnDelete();
            $t->integer('version_number');
            $t->foreignId('changed_by_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('changed_at')->default(DB::raw('NOW()'));
            $t->jsonb('changes')->default('[]');
            $t->string('change_summary', 500)->nullable();
        });
        Schema::table('requirement_versions', function (Blueprint $t) {
            $t->index('requirement_id', 'ix_requirement_versions_requirement');
            $t->index('changed_at', 'ix_requirement_versions_changed_at');
            $t->index(['requirement_id','version_number'], 'ix_requirement_versions_req_ver');
        });
    }
    public function down(): void { Schema::dropIfExists('requirement_versions'); }
};
