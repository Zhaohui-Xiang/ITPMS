<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('superadmin_recovery_credentials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $t->string('credential_hash', 64);
            $t->string('nonce', 64);
            $t->string('deployment_uuid', 36);
            $t->timestampTz('expires_at');
            $t->timestampTz('used_at')->nullable();
            $t->string('operator', 150);
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
        });
        DB::statement('CREATE UNIQUE INDEX ux_recovery_credentials_hash ON superadmin_recovery_credentials (credential_hash)');
    }

    public function down(): void
    {
        Schema::dropIfExists('superadmin_recovery_credentials');
    }
};
