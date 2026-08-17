<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('notification_logs', function (Blueprint $t) {
            $t->id();
            $t->smallInteger('notification_type');
            $t->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $t->string('subject', 500);
            $t->text('content');
            $t->foreignId('related_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $t->foreignId('related_requirement_id')->nullable()->constrained('requirements')->nullOnDelete();
            $t->smallInteger('status');
            $t->text('error_message')->nullable();
            $t->timestampTz('sent_at')->default(DB::raw('NOW()'));
        });
        Schema::table('notification_logs', function (Blueprint $t) {
            $t->index('recipient_id', 'ix_notification_logs_recipient');
            $t->index('sent_at', 'ix_notification_logs_sent_at');
            $t->index(['notification_type','sent_at'], 'ix_notification_logs_type_sent');
            $t->index('related_task_id', 'ix_notification_logs_task');
            $t->index(['related_task_id','notification_type','sent_at'], 'ix_notification_logs_task_type_dedup');
        });
    }
    public function down(): void { Schema::dropIfExists('notification_logs'); }
};
