<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at')->default(DB::raw('NOW()'));
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->unique(['user_id', 'role_id'], 'ux_role_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
