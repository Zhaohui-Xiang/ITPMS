<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('api_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $t->string('api_name', 200);
            $t->string('request_path', 500);
            $t->string('request_method', 10);
            $t->string('auth_type', 50)->nullable();
            $t->jsonb('request_params')->default('[]');
            $t->jsonb('response_params')->default('[]');
            $t->text('rich_text_body')->nullable();
            $t->foreignId('requirement_id')->nullable()->constrained('requirements')->nullOnDelete();
            $t->integer('version')->default(1);
            $t->softDeletes('deleted_at');
            $t->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
        });
        Schema::table('api_documents', function (Blueprint $t) {
            $t->index('project_id', 'ix_api_documents_project');
            $t->index('folder_id', 'ix_api_documents_folder');
            $t->index('requirement_id', 'ix_api_documents_requirement');
            $t->index(['project_id','deleted_at'], 'ix_api_documents_project_deleted');
        });
    }
    public function down(): void { Schema::dropIfExists('api_documents'); }
};
