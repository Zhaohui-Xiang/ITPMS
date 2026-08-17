<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('api_document_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('api_document_id')->constrained('api_documents')->cascadeOnDelete();
            $t->integer('version_number');
            $t->foreignId('updated_by_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
            $t->jsonb('snapshot');
        });
        Schema::table('api_document_versions', function (Blueprint $t) {
            $t->index('api_document_id', 'ix_api_document_versions_doc');
            $t->index(['api_document_id','version_number'], 'ix_api_document_versions_doc_ver');
        });
    }
    public function down(): void { Schema::dropIfExists('api_document_versions'); }
};
