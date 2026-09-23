<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('defect_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('defect_id')->constrained('defects')->cascadeOnDelete();
            $t->string('file', 255);
            $t->string('filename', 255);
            $t->bigInteger('file_size');
            $t->string('file_type', 50)->nullable();
            $t->foreignId('uploaded_by_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('uploaded_at')->default(DB::raw('NOW()'));
            $t->softDeletes('deleted_at');
        });
        Schema::table('defect_attachments', function (Blueprint $t) {
            $t->index('defect_id', 'ix_defect_attachments_defect');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('defect_attachments');
    }
};
