<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_scopes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $t->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $t->string('module', 32);
            $t->string('scope_type', 16);
            // 锚点组织：先同事务改 none 或换锚，禁止直接删组织
            $t->foreignId('org_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
        });
        DB::statement('ALTER TABLE data_scopes ADD CONSTRAINT chk_data_scopes_subject_xor CHECK (num_nonnulls(user_id, role_id, organization_id) = 1)');
        // module × scope_type 完整封闭矩阵（v1.8 冻结）
        DB::statement(<<<'SQL'
            ALTER TABLE data_scopes ADD CONSTRAINT chk_data_scopes_module_matrix CHECK (
              (module IN ('project','requirement','task','defect','document')
               AND scope_type IN ('all','own','org_subtree','projects','none'))
              OR
              (module = 'audit' AND scope_type IN ('all','own','none'))
            )
        SQL);
        DB::statement("ALTER TABLE data_scopes ADD CONSTRAINT chk_data_scopes_org_anchor CHECK ((scope_type = 'org_subtree') = (org_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX ux_data_scopes_user ON data_scopes (module, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ux_data_scopes_role ON data_scopes (module, role_id) WHERE role_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ux_data_scopes_org ON data_scopes (module, organization_id) WHERE organization_id IS NOT NULL');

        Schema::create('data_scope_projects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('data_scope_id')->constrained('data_scopes')->cascadeOnDelete();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
        });
        DB::statement('CREATE UNIQUE INDEX ux_data_scope_projects_pair ON data_scope_projects (data_scope_id, project_id)');

        // 延迟约束：明细归属（父规则必须 scope_type='projects'）+ projects 最小基数（提交时至少一条明细）
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ipms_check_data_scope_projects() RETURNS trigger AS $fn$
            DECLARE
                scope_id bigint;
                parent_type text;
                detail_count int;
            BEGIN
                scope_id := COALESCE(NEW.data_scope_id, OLD.data_scope_id);
                SELECT scope_type INTO parent_type FROM data_scopes WHERE id = scope_id;
                IF parent_type IS NULL THEN
                    -- 父规则同事务级联删除：明细随规则消亡，放行
                    RETURN NULL;
                END IF;
                IF TG_OP <> 'DELETE' AND parent_type <> 'projects' THEN
                    RAISE EXCEPTION 'data_scope_projects 明细只能属于 scope_type=projects 的规则（父规则 % 为 %）', scope_id, parent_type;
                END IF;
                IF parent_type = 'projects' THEN
                    SELECT count(*) INTO detail_count FROM data_scope_projects WHERE data_scope_id = scope_id;
                    IF detail_count = 0 THEN
                        RAISE EXCEPTION 'scope_type=projects 的规则 % 提交时必须至少保留一条项目明细', scope_id;
                    END IF;
                END IF;
                RETURN NULL;
            END;
            $fn$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER tg_data_scope_projects_membership
                AFTER INSERT OR UPDATE OR DELETE ON data_scope_projects
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ipms_check_data_scope_projects();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tg_data_scope_projects_membership ON data_scope_projects');
        DB::unprepared('DROP FUNCTION IF EXISTS ipms_check_data_scope_projects()');
        Schema::dropIfExists('data_scope_projects');
        Schema::dropIfExists('data_scopes');
    }
};
