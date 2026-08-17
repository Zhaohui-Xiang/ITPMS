<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('seed_marker', 64)->nullable();
            $table->index('seed_marker', 'ix_users_seed_marker');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('seed_marker', 64)->nullable();
            $table->index('seed_marker', 'ix_projects_seed_marker');
        });

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS ux_users_username');
        DB::statement('CREATE UNIQUE INDEX ux_users_username_ci ON users (LOWER(username))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ux_users_username_ci');
        DB::statement('ALTER TABLE users ADD CONSTRAINT ux_users_username UNIQUE (username)');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('ix_projects_seed_marker');
            $table->dropColumn('seed_marker');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('ix_users_seed_marker');
            $table->dropColumn('seed_marker');
        });
    }
};
