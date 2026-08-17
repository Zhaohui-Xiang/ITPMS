<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('password', 128);
            $table->timestampTz('last_login')->nullable();
            $table->string('username', 150);
            $table->string('first_name', 150)->default('');
            $table->string('last_name', 150)->default('');
            $table->string('email', 254)->default('');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_staff')->default(false);
            $table->timestampTz('date_joined')->default(DB::raw('NOW()'));
            $table->smallInteger('user_type');
            $table->string('phone', 20)->nullable();
            $table->boolean('must_change_password')->default(true);
            $table->boolean('is_disabled')->default(false);
            $table->string('display_name', 100)->default('');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->default(DB::raw('NOW()'));
            $table->timestampTz('updated_at')->default(DB::raw('NOW()'));
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT chk_users_user_type CHECK (user_type IN (1, 2, 3))');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username', 'ux_users_username');
            $table->index('user_type', 'ix_users_user_type');
            $table->index('email', 'ix_users_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
