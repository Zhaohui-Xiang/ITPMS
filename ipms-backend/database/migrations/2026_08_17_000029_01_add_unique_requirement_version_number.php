<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requirement_versions', function (Blueprint $table) {
            $table->dropIndex('ix_requirement_versions_req_ver');
            $table->unique(
                ['requirement_id', 'version_number'],
                'ux_requirement_versions_req_ver',
            );
        });
    }

    public function down(): void
    {
        Schema::table('requirement_versions', function (Blueprint $table) {
            $table->dropUnique('ux_requirement_versions_req_ver');
            $table->index(
                ['requirement_id', 'version_number'],
                'ix_requirement_versions_req_ver',
            );
        });
    }
};
