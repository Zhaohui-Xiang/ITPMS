<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_epoch', function (Blueprint $t) {
            $t->boolean('id')->primary()->default(true);
            $t->bigInteger('value')->default(0);
            $t->timestampTz('updated_at')->default(DB::raw('NOW()'));
        });
        DB::statement('ALTER TABLE permission_epoch ADD CONSTRAINT chk_permission_epoch_singleton CHECK (id)');
        DB::table('permission_epoch')->insert(['id' => true, 'value' => 0, 'updated_at' => now()]);

        Schema::create('deployment_uuid', function (Blueprint $t) {
            $t->boolean('id')->primary()->default(true);
            $t->string('uuid', 36);
            $t->timestampTz('created_at')->default(DB::raw('NOW()'));
        });
        DB::statement('ALTER TABLE deployment_uuid ADD CONSTRAINT chk_deployment_uuid_singleton CHECK (id)');
        DB::table('deployment_uuid')->insert([
            'id' => true,
            'uuid' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        // ---- epoch 递增触发器：六张规则/关系表（全操作 + TRUNCATE）----
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ipms_perm_bump_epoch() RETURNS trigger AS $fn$
            BEGIN
                UPDATE permission_epoch SET value = value + 1, updated_at = NOW() WHERE id = TRUE;
                RETURN NULL;
            END;
            $fn$ LANGUAGE plpgsql;
        SQL);

        foreach ([
            'permission_rules',
            'data_scopes',
            'data_scope_projects',
            'field_permissions',
            'role_user',
            'permission_role',
        ] as $table) {
            DB::unprepared("CREATE TRIGGER tg_epoch_{$table}
                AFTER INSERT OR UPDATE OR DELETE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION ipms_perm_bump_epoch()");
            DB::unprepared("CREATE TRIGGER tg_epoch_{$table}_truncate
                AFTER TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION ipms_perm_bump_epoch()");
        }

        // 列条件触发：users(user_type, is_active, is_disabled) / organizations(parent_id, org_type, is_active)
        // （UPDATE OF 列限定仅支持 FOR EACH ROW，函数幂等更新单行，开销可忽略）
        DB::unprepared('CREATE TRIGGER tg_epoch_users
            AFTER INSERT OR DELETE OR UPDATE OF user_type, is_active, is_disabled ON users
            FOR EACH ROW EXECUTE FUNCTION ipms_perm_bump_epoch()');
        DB::unprepared('CREATE TRIGGER tg_epoch_organizations
            AFTER INSERT OR DELETE OR UPDATE OF parent_id, org_type, is_active ON organizations
            FOR EACH ROW EXECUTE FUNCTION ipms_perm_bump_epoch()');

        // ---- 主体类型不变量（DEFERRABLE constraint triggers）----
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ipms_check_role_user_type() RETURNS trigger AS $fn$
            DECLARE
                u_type smallint;
                r_type smallint;
            BEGIN
                SELECT user_type INTO u_type FROM users WHERE id = NEW.user_id;
                SELECT user_type INTO r_type FROM roles WHERE id = NEW.role_id;
                IF u_type IS NULL OR r_type IS NULL THEN
                    RAISE EXCEPTION 'role_user 引用的用户或角色不存在';
                END IF;
                IF u_type <> r_type THEN
                    RAISE EXCEPTION 'role_user 类型不变量违反：users.user_type(%) 必须等于 roles.user_type(%)', u_type, r_type;
                END IF;
                RETURN NULL;
            END;
            $fn$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER tg_role_user_type
                AFTER INSERT OR UPDATE ON role_user
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ipms_check_role_user_type();

            CREATE OR REPLACE FUNCTION ipms_check_organization_user_type() RETURNS trigger AS $fn$
            DECLARE
                u_type smallint;
                o_type smallint;
            BEGIN
                SELECT user_type INTO u_type FROM users WHERE id = NEW.user_id;
                SELECT org_type INTO o_type FROM organizations WHERE id = NEW.organization_id;
                IF u_type IS NULL OR o_type IS NULL THEN
                    RAISE EXCEPTION 'organization_user 引用的用户或组织不存在';
                END IF;
                IF u_type <> o_type THEN
                    RAISE EXCEPTION 'organization_user 类型不变量违反：users.user_type(%) 必须等于 organizations.org_type(%)', u_type, o_type;
                END IF;
                RETURN NULL;
            END;
            $fn$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER tg_organization_user_type
                AFTER INSERT OR UPDATE ON organization_user
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ipms_check_organization_user_type();

            CREATE OR REPLACE FUNCTION ipms_check_user_type_change() RETURNS trigger AS $fn$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM role_user ru JOIN roles r ON r.id = ru.role_id
                    WHERE ru.user_id = NEW.id AND r.user_type <> NEW.user_type
                ) THEN
                    RAISE EXCEPTION '用户 % 存在类型不匹配的角色绑定，禁止直接变更 user_type', NEW.id;
                END IF;
                IF EXISTS (
                    SELECT 1 FROM organization_user ou JOIN organizations o ON o.id = ou.organization_id
                    WHERE ou.user_id = NEW.id AND o.org_type <> NEW.user_type
                ) THEN
                    RAISE EXCEPTION '用户 % 存在类型不匹配的组织绑定，禁止直接变更 user_type', NEW.id;
                END IF;
                RETURN NULL;
            END;
            $fn$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER tg_users_type_change
                AFTER UPDATE OF user_type ON users
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ipms_check_user_type_change();

            CREATE OR REPLACE FUNCTION ipms_check_role_type_change() RETURNS trigger AS $fn$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM role_user ru JOIN users u ON u.id = ru.user_id
                    WHERE ru.role_id = NEW.id AND u.user_type <> NEW.user_type
                ) THEN
                    RAISE EXCEPTION '角色 % 存在类型不匹配的用户绑定，禁止直接变更 user_type', NEW.id;
                END IF;
                RETURN NULL;
            END;
            $fn$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER tg_roles_type_change
                AFTER UPDATE OF user_type ON roles
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ipms_check_role_type_change();

            CREATE OR REPLACE FUNCTION ipms_check_org_type_change() RETURNS trigger AS $fn$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM organization_user ou JOIN users u ON u.id = ou.user_id
                    WHERE ou.organization_id = NEW.id AND u.user_type <> NEW.org_type
                ) THEN
                    RAISE EXCEPTION '组织 % 存在类型不匹配的成员绑定，禁止直接变更 org_type', NEW.id;
                END IF;
                RETURN NULL;
            END;
            $fn$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER tg_organizations_type_change
                AFTER UPDATE OF org_type ON organizations
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ipms_check_org_type_change();
        SQL);

        // ---- super_admin 角色实体保护：code/is_system 不可变、不可删 ----
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ipms_protect_superadmin_role() RETURNS trigger AS $fn$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.code = 'super_admin' THEN
                    RAISE EXCEPTION 'super_admin 角色实体不可删除';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.code = 'super_admin' THEN
                    IF NEW.code <> OLD.code OR NEW.is_system IS DISTINCT FROM OLD.is_system THEN
                        RAISE EXCEPTION 'super_admin 角色的 code/is_system 不可变更';
                    END IF;
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $fn$ LANGUAGE plpgsql;

            CREATE TRIGGER tg_roles_protect_superadmin
                BEFORE UPDATE OR DELETE ON roles
                FOR EACH ROW EXECUTE FUNCTION ipms_protect_superadmin_role();
        SQL);

        // ---- 组织图约束：parent_id <> id + 父子同 org_type + 无环（触发器辅助）----
        DB::statement('ALTER TABLE organizations ADD CONSTRAINT chk_organizations_parent_not_self CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ipms_check_organization_graph() RETURNS trigger AS $fn$
            DECLARE
                parent_type smallint;
                cycle_found boolean;
            BEGIN
                IF NEW.parent_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT org_type INTO parent_type FROM organizations WHERE id = NEW.parent_id;
                IF parent_type IS NULL THEN
                    RAISE EXCEPTION '父组织 % 不存在', NEW.parent_id;
                END IF;
                IF parent_type <> NEW.org_type THEN
                    RAISE EXCEPTION '父子组织 org_type 必须一致（父 % / 子 %）', parent_type, NEW.org_type;
                END IF;
                -- 祖先链检查：NEW.id 出现在 NEW.parent_id 的祖先链中即成环
                WITH RECURSIVE ancestors AS (
                    SELECT id, parent_id FROM organizations WHERE id = NEW.parent_id
                    UNION
                    SELECT o.id, o.parent_id FROM organizations o
                    JOIN ancestors a ON a.parent_id = o.id
                )
                SELECT TRUE INTO cycle_found FROM ancestors WHERE id = NEW.id LIMIT 1;
                IF cycle_found THEN
                    RAISE EXCEPTION '组织树禁止环：% 已是 % 的祖先', NEW.id, NEW.parent_id;
                END IF;
                RETURN NEW;
            END;
            $fn$ LANGUAGE plpgsql;

            CREATE TRIGGER tg_organizations_graph
                BEFORE INSERT OR UPDATE OF parent_id, org_type ON organizations
                FOR EACH ROW EXECUTE FUNCTION ipms_check_organization_graph();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS tg_organizations_graph ON organizations;
            DROP FUNCTION IF EXISTS ipms_check_organization_graph();
            DROP TRIGGER IF EXISTS tg_roles_protect_superadmin ON roles;
            DROP FUNCTION IF EXISTS ipms_protect_superadmin_role();
            DROP TRIGGER IF EXISTS tg_organizations_type_change ON organizations;
            DROP TRIGGER IF EXISTS tg_roles_type_change ON roles;
            DROP TRIGGER IF EXISTS tg_users_type_change ON users;
            DROP TRIGGER IF EXISTS tg_organization_user_type ON organization_user;
            DROP TRIGGER IF EXISTS tg_role_user_type ON role_user;
            DROP FUNCTION IF EXISTS ipms_check_org_type_change();
            DROP FUNCTION IF EXISTS ipms_check_role_type_change();
            DROP FUNCTION IF EXISTS ipms_check_user_type_change();
            DROP FUNCTION IF EXISTS ipms_check_organization_user_type();
            DROP FUNCTION IF EXISTS ipms_check_role_user_type();
            DROP TRIGGER IF EXISTS tg_epoch_users ON users;
            DROP TRIGGER IF EXISTS tg_epoch_organizations ON organizations;
        SQL);
        foreach ([
            'permission_rules',
            'data_scopes',
            'data_scope_projects',
            'field_permissions',
            'role_user',
            'permission_role',
        ] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS tg_epoch_{$table} ON {$table}");
            DB::unprepared("DROP TRIGGER IF EXISTS tg_epoch_{$table}_truncate ON {$table}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS ipms_perm_bump_epoch()');
        Schema::dropIfExists('deployment_uuid');
        Schema::dropIfExists('permission_epoch');
    }
};
