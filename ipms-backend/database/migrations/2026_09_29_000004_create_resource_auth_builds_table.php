<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_auth_builds', function (Blueprint $t) {
            $t->string('resource', 32);
            $t->string('closure', 8);
            $t->string('build_id', 64);
            $t->string('digest', 64);
            $t->string('status', 16);
            $t->timestampTz('activated_at')->nullable();
            $t->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->primary(['resource', 'closure', 'build_id']);
        });
        DB::statement("ALTER TABLE resource_auth_builds ADD CONSTRAINT chk_resource_auth_builds_closure CHECK (closure IN ('read','write'))");
        DB::statement("ALTER TABLE resource_auth_builds ADD CONSTRAINT chk_resource_auth_builds_status CHECK (status IN ('candidate','current','retiring','revoked'))");
        // 每个资源×闭包至多一个 current（promote 单事务：current→retiring 与 candidate→current）
        DB::statement("CREATE UNIQUE INDEX ux_resource_auth_builds_current ON resource_auth_builds (resource, closure) WHERE status = 'current'");
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_auth_builds');
    }
};
