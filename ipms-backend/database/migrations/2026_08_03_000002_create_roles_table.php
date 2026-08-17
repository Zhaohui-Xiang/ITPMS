<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('name', 50); $table->string('code', 50);
            $table->smallInteger('user_type'); $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestampTz('created_at')->default(DB::raw('NOW()'));
            $table->timestampTz('updated_at')->default(DB::raw('NOW()'));
        });
        DB::statement('ALTER TABLE roles ADD CONSTRAINT chk_roles_user_type CHECK (user_type IN (1,2,3))');
        Schema::table('roles', fn($t) => $t->unique('code', 'ux_roles_code'));
    }
    public function down(): void { Schema::dropIfExists('roles'); }
};
