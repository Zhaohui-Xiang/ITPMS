<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $t->string('title', 200);
            $t->string('file', 255)->nullable();
            $t->bigInteger('file_size')->default(0);
            $t->string('file_type', 50)->nullable();
            $t->foreignId('uploaded_by_id')->constrained('users')->restrictOnDelete();
            $t->integer('version')->default(1);
            $t->softDeletes('deleted_at');
            $t->foreignId('deleted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
        });
        Schema::table('documents', function (Blueprint $t) {
            $t->index('project_id', 'ix_documents_project');
            $t->index('folder_id', 'ix_documents_folder');
            $t->index(['project_id','deleted_at'], 'ix_documents_project_deleted');
        });
    }
    public function down(): void { Schema::dropIfExists('documents'); }
};
