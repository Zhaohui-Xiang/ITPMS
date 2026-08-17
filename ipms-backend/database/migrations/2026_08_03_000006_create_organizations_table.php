<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $t->string('name', 100);
            $t->smallInteger('org_type');
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
        });
        DB::statement('ALTER TABLE organizations ADD CONSTRAINT chk_organizations_org_type CHECK (org_type IN (1,2,3))');
        Schema::table('organizations', function (Blueprint $t) {
            $t->index('parent_id', 'ix_organizations_parent');
            $t->index('org_type', 'ix_organizations_org_type');
        });
    }
    public function down(): void { Schema::dropIfExists('organizations'); }
};
