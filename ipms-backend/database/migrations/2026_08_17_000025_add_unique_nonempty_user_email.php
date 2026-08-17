<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX ux_users_email_nonempty_ci ON users (LOWER(email)) WHERE email <> ''");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ux_users_email_nonempty_ci');
    }
};
