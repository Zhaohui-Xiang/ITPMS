<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100);
            $table->string('name', 100);
            $table->string('module', 50);
            $table->string('action', 50);
            $table->text('description')->nullable();
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->unique('code', 'ux_permissions_code');
            $table->index('module', 'ix_permissions_module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
