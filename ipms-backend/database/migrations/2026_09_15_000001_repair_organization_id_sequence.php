<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE organizations IN ACCESS EXCLUSIVE MODE');
            if (DB::table('organizations')->max('id') === null) {
                return;
            }
            // Legacy seeds inserted explicit IDs without advancing the sequence.
            DB::statement("SELECT setval(pg_get_serial_sequence('organizations', 'id'),
                GREATEST((SELECT MAX(id) FROM organizations),
                    (SELECT last_value FROM organizations_id_seq)), true)");
        });
    }

    public function down(): void
    {
        // Never rewind an identity sequence during rollback.
    }
};
