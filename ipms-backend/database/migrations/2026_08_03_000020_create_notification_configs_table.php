<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('notification_configs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->boolean('remind_enabled')->default(true);
            $t->smallInteger('remind_days_before')->default(1);
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
        });
        Schema::table('notification_configs', function (Blueprint $t) {
            $t->unique('user_id', 'ux_notification_configs_user');
        });
    }
    public function down(): void { Schema::dropIfExists('notification_configs'); }
};
