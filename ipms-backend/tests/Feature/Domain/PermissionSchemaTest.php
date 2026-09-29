<?php

namespace Tests\Feature\Domain;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * 权限架构 Phase A 数据库级约束验收。
 *
 * 注意：CHECK/FK/普通触发器即时生效，可直接断言；
 * DEFERRABLE INITIALLY DEFERRED 约束触发器只在真实 COMMIT 时触发，
 * 而 RefreshDatabase 测试事务永不提交，因此延迟约束用例走独立 PDO 连接真提交。
 */
final class PermissionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private ?PDO $pdo = null;

    protected function tearDown(): void
    {
        $this->pdo = null;
        parent::tearDown();
    }

    /** 独立于测试事务的连接：用于触发延迟约束的真实提交 */
    private function directPdo(): PDO
    {
        if ($this->pdo === null) {
            $config = config('database.connections.pgsql');
            $this->pdo = new PDO(
                "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
                $config['username'],
                $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        }
        return $this->pdo;
    }

    /** 直接连接上执行并提交；返回异常消息（无异常返回 null） */
    private function committedViolation(array $statements): ?string
    {
        $pdo = $this->directPdo();
        $pdo->beginTransaction();
        try {
            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }
            $pdo->commit();
            return null;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $e->getMessage();
        }
    }

    private function insertPermission(PDO $pdo): int
    {
        $pdo->exec("INSERT INTO permissions (code, name, module, action) VALUES ('test.perm', '测试权限', 'test', 'perm') ON CONFLICT DO NOTHING");
        return (int) $pdo->query("SELECT id FROM permissions WHERE code = 'test.perm'")->fetchColumn();
    }

    private function insertUser(PDO $pdo, string $name, int $type): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, password, email, user_type, is_active, is_staff, must_change_password, is_disabled, display_name, date_joined, created_at, updated_at)
             VALUES (?, 'x', ?, ?, true, false, false, false, ?, NOW(), NOW(), NOW()) RETURNING id"
        );
        $stmt->execute([$name, $name.'@t.local', $type, $name]);
        return (int) $stmt->fetchColumn();
    }

    private function insertRole(PDO $pdo, string $code, int $type): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO roles (name, code, user_type, is_system) VALUES (?, ?, ?, true) RETURNING id"
        );
        $stmt->execute([$code, $code, $type]);
        return (int) $stmt->fetchColumn();
    }

    private function insertOrg(PDO $pdo, string $name, int $type, ?int $parent = null): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO organizations (name, org_type, parent_id, is_active) VALUES (?, ?, ?, true) RETURNING id'
        );
        $stmt->execute([$name, $type, $parent]);
        return (int) $stmt->fetchColumn();
    }

    private function insertProject(PDO $pdo, string $name, int $managerId): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO projects (name, system_type, manager_id, created_by_id) VALUES (?, 2, ?, ?) RETURNING id'
        );
        $stmt->execute([$name, $managerId, $managerId]);
        return (int) $stmt->fetchColumn();
    }

    private function cleanup(PDO $pdo): void
    {
        // 按依赖逆序清掉本次直接提交的数据（规则先删，明细级联随之消亡）
        foreach ([
            "DELETE FROM data_scopes WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'schema_t_%') OR organization_id IN (SELECT id FROM organizations WHERE name LIKE 'schema_t_%')",
            "DELETE FROM permission_rules WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'schema_t_%') OR role_id IN (SELECT id FROM roles WHERE code LIKE 'schema_t_%')",
            "DELETE FROM role_user WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'schema_t_%')",
            "DELETE FROM organization_user WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'schema_t_%')",
            "DELETE FROM organizations WHERE name LIKE 'schema_t_%'",
            "DELETE FROM projects WHERE name LIKE 'schema_t_%'",
            "DELETE FROM role_user WHERE role_id IN (SELECT id FROM roles WHERE code LIKE 'schema_t_%')",
            "DELETE FROM roles WHERE code LIKE 'schema_t_%'",
            "DELETE FROM users WHERE username LIKE 'schema_t_%'",
            "DELETE FROM permissions WHERE code = 'test.perm'",
        ] as $sql) {
            $pdo->exec($sql);
        }
    }

    // ---- 即时约束（测试事务内直接断言） ----

    public function test_permission_rules_subject_xor_and_effect_check(): void
    {
        $pdo = $this->directPdo();
        $permissionId = $this->insertPermission($pdo);
        $userId = $this->insertUser($pdo, 'schema_t_u1', 1);
        $roleId = $this->insertRole($pdo, 'schema_t_r1', 1);

        // 双主体 → XOR 拒绝
        $error = $this->committedViolation([sprintf(
            "INSERT INTO permission_rules (permission_id, user_id, role_id, effect) VALUES (%d, %d, %d, 'allow')",
            $permissionId, $userId, $roleId,
        )]);
        $this->assertStringContainsString('chk_permission_rules_subject_xor', $error);

        // 非法 effect → CHECK 拒绝
        $error = $this->committedViolation([sprintf(
            "INSERT INTO permission_rules (permission_id, user_id, effect) VALUES (%d, %d, 'maybe')",
            $permissionId, $userId,
        )]);
        $this->assertStringContainsString('chk_permission_rules_effect', $error);

        // 合法行可写入
        $error = $this->committedViolation([sprintf(
            "INSERT INTO permission_rules (permission_id, user_id, effect) VALUES (%d, %d, 'allow')",
            $permissionId, $userId,
        )]);
        $this->assertNull($error);

        // 重复主体+权限 → 部分唯一索引拒绝
        $error = $this->committedViolation([sprintf(
            "INSERT INTO permission_rules (permission_id, user_id, effect) VALUES (%d, %d, 'deny')",
            $permissionId, $userId,
        )]);
        $this->assertNotNull($error);

        $this->cleanup($pdo);
    }

    public function test_data_scopes_closed_module_matrix_and_org_anchor(): void
    {
        $pdo = $this->directPdo();
        $userId = $this->insertUser($pdo, 'schema_t_u2', 1);

        $cases = [
            // [module, scope_type, org_id, 期望被拒]
            ['audit', 'projects', null, true],     // audit 不支持 projects
            ['audit', 'org_subtree', null, true],  // audit 不支持 org_subtree
            ['task', 'invalid_scope', null, true], // 未知 scope_type
            ['unknown_module', 'all', null, true], // 未知 module
            ['task', 'org_subtree', null, true],   // org_subtree 缺锚点
        ];
        foreach ($cases as [$module, $scopeType, $orgId, $rejected]) {
            $orgSql = $orgId === null ? 'NULL' : $orgId;
            $error = $this->committedViolation([sprintf(
                "INSERT INTO data_scopes (user_id, module, scope_type, org_id) VALUES (%d, '%s', '%s', %s)",
                $userId, $module, $scopeType, $orgSql,
            )]);
            $this->assertNotNull($error, "{$module}×{$scopeType} 应被拒绝");
        }

        // 合法组合：audit×all / task×own / document×none 可写入
        foreach ([['audit', 'all'], ['task', 'own'], ['document', 'none']] as [$module, $scopeType]) {
            $error = $this->committedViolation([sprintf(
                "INSERT INTO data_scopes (user_id, module, scope_type) VALUES (%d, '%s', '%s')",
                $userId, $module, $scopeType,
            )]);
            $this->assertNull($error, "{$module}×{$scopeType} 应可写入");
        }

        $this->cleanup($pdo);
    }

    public function test_scope_detail_membership_and_min_cardinality_are_deferred(): void
    {
        $pdo = $this->directPdo();
        $userId = $this->insertUser($pdo, 'schema_t_u3', 1);
        $projectId = $this->insertProject($pdo, 'schema_t_proj', $userId);

        // scope_type=all 的规则挂明细 → 延迟触发器在提交时拒绝（明细归属）
        $error = $this->committedViolation([
            "INSERT INTO data_scopes (user_id, module, scope_type) VALUES ({$userId}, 'task', 'all') RETURNING id",
        ]);
        $this->assertNull($error);
        $scopeId = (int) $pdo->query("SELECT id FROM data_scopes WHERE user_id = {$userId}")->fetchColumn();
        $error = $this->committedViolation([
            "INSERT INTO data_scope_projects (data_scope_id, project_id) VALUES ({$scopeId}, {$projectId})",
        ]);
        $this->assertStringContainsString('明细只能属于 scope_type=projects', (string) $error);

        // projects 规则 + 明细 → 可提交；删掉最后一条明细 → 提交时被拒（最小基数）
        $pdo->exec("UPDATE data_scopes SET scope_type = 'projects' WHERE id = {$scopeId}");
        $error = $this->committedViolation([
            "INSERT INTO data_scope_projects (data_scope_id, project_id) VALUES ({$scopeId}, {$projectId})",
        ]);
        $this->assertNull($error);
        $error = $this->committedViolation([
            "DELETE FROM data_scope_projects WHERE data_scope_id = {$scopeId}",
        ]);
        $this->assertStringContainsString('至少保留一条项目明细', (string) $error);

        $this->cleanup($pdo);
    }

    public function test_anchor_org_delete_is_restricted(): void
    {
        $pdo = $this->directPdo();
        // 主体=用户、锚点=组织（主体若同为该组织会被主体级联先行删除，掩盖锚点约束）
        $userId = $this->insertUser($pdo, 'schema_t_anchor_user', 1);
        $orgId = $this->insertOrg($pdo, 'schema_t_anchor', 1);
        $this->committedViolation([
            "INSERT INTO data_scopes (user_id, module, scope_type, org_id)
             VALUES ({$userId}, 'project', 'org_subtree', {$orgId})",
        ]);

        $error = $this->committedViolation(["DELETE FROM organizations WHERE id = {$orgId}"]);
        $this->assertNotNull($error, '锚点组织被 data_scopes 引用时必须 RESTRICT');

        // 换锚后放行
        $this->assertNull($this->committedViolation([
            "UPDATE data_scopes SET scope_type = 'none', org_id = NULL WHERE user_id = {$userId}",
            "DELETE FROM organizations WHERE id = {$orgId}",
        ]));

        $this->cleanup($pdo);
    }

    public function test_subject_type_invariants_via_deferred_triggers(): void
    {
        $pdo = $this->directPdo();
        $internalUser = $this->insertUser($pdo, 'schema_t_u4', 1);
        $supplierUser = $this->insertUser($pdo, 'schema_t_u5', 2);
        $internalRole = $this->insertRole($pdo, 'schema_t_r2', 1);
        $supplierRole = $this->insertRole($pdo, 'schema_t_r3', 2);
        $internalOrg = $this->insertOrg($pdo, 'schema_t_o1', 1);

        // 跨类型角色绑定 → 提交时拒绝
        $error = $this->committedViolation([
            "INSERT INTO role_user (user_id, role_id) VALUES ({$supplierUser}, {$internalRole})",
        ]);
        $this->assertStringContainsString('类型不变量', (string) $error);

        // 跨类型组织成员 → 提交时拒绝
        $error = $this->committedViolation([
            "INSERT INTO organization_user (user_id, organization_id) VALUES ({$supplierUser}, {$internalOrg})",
        ]);
        $this->assertStringContainsString('类型不变量', (string) $error);

        // 同类型绑定可提交
        $this->assertNull($this->committedViolation([
            "INSERT INTO role_user (user_id, role_id) VALUES ({$internalUser}, {$internalRole})",
            "INSERT INTO role_user (user_id, role_id) VALUES ({$supplierUser}, {$supplierRole})",
        ]));

        // 带绑定改用户类型 → 提交时拒绝
        $error = $this->committedViolation([
            "UPDATE users SET user_type = 3 WHERE id = {$internalUser}",
        ]);
        $this->assertStringContainsString('禁止直接变更', (string) $error);

        // 带绑定改角色类型 → 拒绝
        $error = $this->committedViolation([
            "UPDATE roles SET user_type = 3 WHERE id = {$supplierRole}",
        ]);
        $this->assertStringContainsString('禁止直接变更', (string) $error);

        $this->cleanup($pdo);
    }

    public function test_superadmin_role_entity_is_immutable_and_undeletable(): void
    {
        $pdo = $this->directPdo();
        // 保护触发器即时生效：每条语句独立事务断言后回滚（PG 报错会中止当前事务），零残留
        $errors = [];
        foreach ([
            "UPDATE roles SET code = 'root_admin' WHERE id = %d",
            "UPDATE roles SET is_system = false WHERE id = %d",
            "DELETE FROM roles WHERE id = %d",
        ] as $sql) {
            $pdo->beginTransaction();
            $roleId = $this->insertRole($pdo, 'super_admin', 1);
            try {
                $pdo->exec(sprintf($sql, $roleId));
                $errors[] = null;
            } catch (PDOException $e) {
                $errors[] = $e->getMessage();
            }
            $pdo->rollBack();
        }
        $this->assertStringContainsString('super_admin', (string) $errors[0]);
        $this->assertStringContainsString('super_admin', (string) $errors[1]);
        $this->assertStringContainsString('super_admin', (string) $errors[2]);

        // 名称/描述仍可维护
        $pdo->beginTransaction();
        $roleId = $this->insertRole($pdo, 'super_admin', 1);
        $pdo->exec("UPDATE roles SET name = '超级管理员', description = 'ok' WHERE id = {$roleId}");
        $pdo->rollBack();
        $this->assertTrue(true);
    }

    public function test_organization_graph_rejects_cycles_type_mismatch_and_self_parent(): void
    {
        $pdo = $this->directPdo();
        $a = $this->insertOrg($pdo, 'schema_t_g1', 1);
        $b = $this->insertOrg($pdo, 'schema_t_g2', 1);
        $supplier = $this->insertOrg($pdo, 'schema_t_g3', 2);

        // 自环
        $error = $this->committedViolation(["UPDATE organizations SET parent_id = {$a} WHERE id = {$a}"]);
        $this->assertNotNull($error);

        // 跨类型父节点
        $error = $this->committedViolation(["UPDATE organizations SET parent_id = {$supplier} WHERE id = {$a}"]);
        $this->assertStringContainsString('org_type 必须一致', (string) $error);

        // 合法挂接 A←B
        $this->assertNull($this->committedViolation([
            "UPDATE organizations SET parent_id = {$a} WHERE id = {$b}",
        ]));
        // 反向挂接成环 B←A → 拒绝
        $error = $this->committedViolation(["UPDATE organizations SET parent_id = {$b} WHERE id = {$a}"]);
        $this->assertStringContainsString('禁止环', (string) $error);

        $this->cleanup($pdo);
    }

    public function test_epoch_bumps_on_rule_and_relation_writes(): void
    {
        $pdo = $this->directPdo();
        $before = (int) $pdo->query('SELECT value FROM permission_epoch')->fetchColumn();

        $permissionId = $this->insertPermission($pdo);
        $userId = $this->insertUser($pdo, 'schema_t_u6', 1);
        $this->committedViolation([sprintf(
            "INSERT INTO permission_rules (permission_id, user_id, effect) VALUES (%d, %d, 'allow')",
            $permissionId, $userId,
        )]);
        $afterRule = (int) $pdo->query('SELECT value FROM permission_epoch')->fetchColumn();
        $this->assertGreaterThan($before, $afterRule, '规则写入必须递增 epoch');

        // users 非授权字段变更不推 epoch；授权字段变更推 epoch
        $pdo->exec("UPDATE users SET display_name = 'x' WHERE id = {$userId}");
        $this->assertSame($afterRule, (int) $pdo->query('SELECT value FROM permission_epoch')->fetchColumn());
        $pdo->exec("UPDATE users SET is_active = false WHERE id = {$userId}");
        $this->assertGreaterThan($afterRule, (int) $pdo->query('SELECT value FROM permission_epoch')->fetchColumn());

        $this->cleanup($pdo);
    }

    public function test_resource_auth_builds_single_current_per_resource_closure(): void
    {
        $pdo = $this->directPdo();
        $pdo->exec("INSERT INTO resource_auth_builds (resource, closure, build_id, digest, status) VALUES ('defect', 'read', 'b1', md5('a'), 'current')");
        $error = $this->committedViolation([
            "INSERT INTO resource_auth_builds (resource, closure, build_id, digest, status) VALUES ('defect', 'read', 'b2', md5('b'), 'current')",
        ]);
        $this->assertNotNull($error, '同一资源同一闭包禁止两个 current');

        // 状态机外的非法状态被拒
        $error = $this->committedViolation([
            "INSERT INTO resource_auth_builds (resource, closure, build_id, digest, status) VALUES ('defect', 'read', 'b3', md5('c'), 'limbo')",
        ]);
        $this->assertStringContainsString('chk_resource_auth_builds_status', (string) $error);

        $pdo->exec("DELETE FROM resource_auth_builds WHERE resource = 'defect'");
    }
}
