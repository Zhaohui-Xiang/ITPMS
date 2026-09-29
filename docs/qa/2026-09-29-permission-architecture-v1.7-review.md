# 权限架构重构设计 v1.7 六次复审报告

## 1. 审查信息

- 审查日期：2026-09-29
- 审查对象：`docs/08-权限架构重构设计.md`，v1.7
- 仓库 HEAD：`3ce0e31beb08ab390932d7cb1d6fafe63966b906`
- v1.7 文档提交：`3ce0e31`
- 文档 SHA-256：`a3ca64a38ea2040fa1a2f53ed1d2b409c75e923ae46b59b1c08174ea4a9e4a33`
- 云端仓库：`/srv/itpms-dev/repo`
- 分支：`feature/core-workflow-redesign`
- 前置报告：`docs/qa/2026-09-29-permission-architecture-v1.6-review.md`
- 审查方式：主审与两名全新独立审计 Agent 分别执行 V16-01～06 回归和对抗性审查，再由主审复核云端设计、现有模型、迁移和部署脚本。
- 审查边界：静态审计；未运行应用测试或构建，未写数据库，未修改设计正文、应用代码或部署环境。

本报告中的设计行号对应上述 SHA。当前代码仅用于验证设计能否在现有系统中唯一、安全落地，不表示尚未实现的 v1.7 已构成线上运行时故障。

## 2. 总体结论

**v1.7 已关闭 V16-01、V16-02、V16-06；V16-03、V16-04、V16-05 仍为部分关闭。本轮另发现 5 项新 P1，共计 8 项 P1，当前不建议批准进入开发。**

未发现 P0。阻断项如下：

| 编号 | 优先级 | 问题 |
|------|--------|------|
| V17-01 | P1 | data_scopes 合法矩阵未冻结 module/scope_type 完整值域 |
| V17-02 | P1 | 双摘要状态模型无法表达 current/candidate/retiring 的独立生命周期 |
| V17-03 | P1 | 离线超管恢复凭证没有停机、过期、消费和防重放契约 |
| V17-04 | P1 | current/candidate 共享权限缓存，但授权摘要未进入缓存键 |
| V17-05 | P1 | epoch 与规则快照之间缺少一致性读取协议 |
| V17-06 | P1 | 用户、角色、组织的 user_type/org_type 关联没有数据库不变量 |
| V17-07 | P1 | queue/scheduler/CLI 只有 stale gate，没有执行时身份与重新授权契约 |
| V17-08 | P1 | 组织树无环、同类型和 inactive 截断语义未冻结 |

## 3. V16 回归结果

| V16 编号 | v1.7 状态 | 六次复审结论 |
|----------|-----------|--------------|
| V16-01 操作资格公式被删 | **已关闭** | 第 44～57 行恢复公式、权威清单、non_overridable 和超管不绕过规则 |
| V16-02 epoch 触发/命名空间 | **已关闭** | 第 92～98 行补齐授权字段、deployment_uuid 和恢复/重建测试 |
| V16-03 data_scopes 完整性 | **部分关闭** | 最小基数和锚点删除已补，但合法矩阵仍允许未知 module/scope_type |
| V16-04 双版本握手/全通道 gate | **部分关闭** | 已增加双摘要和后台 gate，但状态字段无法表达 retiring，缓存也未按摘要隔离 |
| V16-05 超管前置断言/恢复 | **部分关闭** | 已补部署断言和恢复命令，但所谓一次性凭证仍可重放，离线条件不可执行验证 |
| V16-06 完整停写顺序 | **已关闭** | 第 146～158 行覆盖入口、FPM、queue、scheduler、CLI、数据库写保护和双写备选 |

## 4. 详细发现与修改建议

### V17-01 [P1] data_scopes 合法矩阵未冻结完整值域

**设计定位**

- 第 67～75 行声称基数和约束“全部数据库级可执行”。
- 第 70 行实际 CHECK 只有：`module <> 'audit' OR scope_type IN ('all','own','none')`。
- 第 81～88 行才列出权威 module × scope_type 矩阵。

**失败场景**

以下数据仍能通过文档给出的 CHECK：

- `module='project', scope_type='bogus', org_id=NULL`；
- `module='audti', scope_type='all'`；
- `module='unknown', scope_type='projects'`。

Resolver 对未知 module/scope_type 没有唯一行为，可能报错、回退默认或得到空集，数据库完整性声明不成立。

**建议修改条文**

将第 70 行替换为完整封闭矩阵：

```sql
CHECK (
  (module IN ('project','requirement','task','defect','document')
   AND scope_type IN ('all','own','org_subtree','projects','none'))
  OR
  (module = 'audit'
   AND scope_type IN ('all','own','none'))
)
```

同时增加：

> `module`、`scope_type` 均为 NOT NULL。新增模块或 scope_type 必须先通过 migration 更新合法矩阵；旧约束未更新时数据库拒绝写入，禁止使用“未知值回退默认”。

验收增加未知 module、拼写错误 scope_type、audit 非法组合和直接 SQL 插入测试。

### V17-02 [P1] 双摘要状态无法表达三种构建的独立生命周期

**设计定位**

- 第 110～119 行在一行中保存 current_digest、candidate_digest 和单一 state。
- 第 123 行要求新摘要成为 current、旧摘要进入 retiring。
- 现有字段没有 retiring_digest/retiring_build，也没有 current、candidate 各自的读写闭包激活状态。

**失败场景**

构建 A 为 current、B 为 candidate。切流至 B 后、关闭回滚窗口前：

- gate 若只接受 current_digest=A，B 全部返回 503；
- gate 若无条件接受 current/candidate，失败或过期 candidate 仍可能继续执行；
- 把 current_digest 改为 B 后，A 的摘要没有字段保存，无法执行安全回滚；
- 单一 read/write 激活状态会让 B 沿用 A 的闭包证明。

**建议修改条文**

将单行双摘要改为构建级子表：

```text
resource_auth_builds(
  resource,
  closure,              -- read | write
  build_id,
  digest,
  status,               -- candidate | current | retiring | revoked
  activated_at,
  activated_by,
  PRIMARY KEY(resource, closure, build_id)
)
```

冻结状态机：

1. prepare：B 插入 candidate，仅候选验证流量可用；
2. promote 单事务：A current→retiring，B candidate→current；
3. rollback 单事务执行逆转换；
4. 关闭回滚窗口：A retiring→revoked；
5. gate 必须同时匹配 resource、closure、当前进程 build_id、digest 和允许状态。

每个 build 分别完成 read/write 激活，禁止继承另一 build 的闭包证明。

### V17-03 [P1] 超管恢复凭证不具备真正的一次性和离线属性

**设计定位**

- 第 136～140 行增加部署断言和 `ipms:recover-superadmin`。
- 第 138 行只规定“控制台可执行”和“环境变量读取”，没有定义停机检查、凭证有效期、消费状态或重放拒绝。

**失败场景**

静态环境变量可以在系统在线时被重复使用。运维人员连续执行两次恢复命令，当前设计没有可执行条件阻止第二次调用，也没有规定只允许在有效超管数量为零时使用。

**建议修改条文**

> 恢复命令仅在有效超管数为 0 时允许执行；执行前必须验证 nginx 维护锁存在，FPM、queue、scheduler 均已停止。凭证绑定 `deployment_uuid + target_user_id + nonce`，有效期不超过 15 分钟。系统只持久化凭证哈希、expires_at、used_at；在同一 advisory-lock 事务内原子标记 used_at，过期或重放立即拒绝。审计记录 operator、target、before/after、build_id，但不得记录原始凭证。执行成功后撤销目标账户全部旧会话并强制修改密码。

验收增加在线执行拒绝、非零超管拒绝、过期、重放、并发双执行和审计脱敏测试。

### V17-04 [P1] 双版本共享缓存但摘要未进入缓存键

**设计定位**

- 第 97 行缓存键只有 `deployment_uuid + epoch`。
- 第 108～124 行允许 current/candidate 两种消费者摘要同时有效。
- current 和 candidate 共享数据库及缓存配置。

**失败场景**

A/B 构建对同一字段或动作产生不同授权结果，但 deployment_uuid 和 epoch 相同。B 可直接命中 A 写入的权限缓存；消费者代码变化本身不会推进 epoch，stale gate 通过也无法识别缓存内容来自另一摘要。

**建议修改条文**

冻结缓存键：

```text
perm:{deployment_uuid}:{accepted_auth_digest}:{epoch}:{decision_kind}:{subject}:{arguments_hash}
```

其中 `accepted_auth_digest` 必须由当前进程根据自身 build_id 计算，并已通过 stale gate；禁止使用可变的 current 别名。不同摘要之间不得预热、复制或读取缓存；retiring 构建只能使用自身命名空间。

验收：A/B 在同一 Redis、同一 epoch 下产生相反判定，按 A→B、B→A、B 失败回滚 A 三种顺序验证零交叉命中。

### V17-05 [P1] epoch 与权限快照之间没有一致性读取协议

**设计定位**

- 第 94～98 行定义 epoch 递增和缓存键，但没有冻结读取 epoch、读取规则和写缓存的顺序。

**失败场景**

请求先读取旧规则 allow；并发事务撤权并把 epoch 从 E 更新为 E+1；请求随后读取新 epoch，并把旧 allow 写入 E+1 命名空间。之后所有请求都会在新 epoch 下命中错误 allow。

**建议修改条文**

冻结权限计算协议：

1. 从主库读取 e1；
2. 按 e1 查缓存；未命中时从主库计算完整权限，禁止从只读副本读取规则；
3. 从主库再次读取 e2；
4. 仅当 e1=e2 时返回并写入 e1 键；否则丢弃结果并重试；
5. 有界重试仍不稳定时 fail closed，且不得写缓存。

缓存命中也应在返回前校验 epoch 未变化。验收使用并发屏障卡在首次读 epoch、规则计算、二次读 epoch和缓存写入前，证明旧结果不会进入新 epoch 键。

### V17-06 [P1] 主体类型与角色/组织关联缺少数据库不变量

**设计定位**

- 第 29、33～42 行让全部直接组织和角色参与权限计算。
- 第 61～75 行只约束规则表，没有约束 role_user、organization_user 的类型兼容性。
- 第 96 行确认 users.user_type 和 organizations.org_type 会影响授权。

**代码依据**

- role_user 当前只有独立 FK 和唯一约束：`ipms-backend/database/migrations/2026_08_03_000005_create_role_user_table.php:12-22`。
- organization_user 同样没有类型一致性约束：`ipms-backend/database/migrations/2026_08_03_000007_create_organization_user_table.php:8-18`。
- 当前用户角色输入只验证角色存在：`ipms-backend/app/Http/Controllers/Api/UserController.php:91-114`。

**失败场景**

供应商账户被直接绑定内部角色，或系统用户被加入供应商组织。由于全部角色和直接组织都会参与权限代数，该账户可以获得不属于其用户类型的 allow、数据范围或字段规则。

**建议修改条文**

冻结数据库不变量：

- `users.user_type = roles.user_type`；
- `users.user_type = organizations.org_type`；
- super_admin 只能绑定内部用户类型。

对 role_user/organization_user 的 INSERT/UPDATE，以及 users/roles/organizations 类型 UPDATE 增加 DEFERRABLE constraint trigger。类型变更事务顺序：锁用户 → 锁关联角色和组织 → 校验或迁移全部绑定 → 更新类型 → 推进 epoch → 提交；不能完整迁移则整体拒绝。

Phase A 先扫描存量错配。验收覆盖直接 SQL 跨类型插入、带绑定改类型、并发改类型/加角色和超管改为非内部类型。

### V17-07 [P1] 后台任务没有执行时重新授权契约

**设计定位**

- 第 20 行确认 Redis queue 且 after_commit=false。
- 第 126 行只要求 queue/scheduler/CLI 执行摘要 stale gate。
- stale gate 无法发现账户被禁用、deny 生效、数据范围收窄或动作资格变化。

**失败场景**

用户在有权限时触发后台导出，任务排队后该用户被禁用或移出项目。摘要仍完全有效，worker 若沿用入队时的“已授权”结果，仍会导出已无权访问的数据。

**建议修改条文**

作业信封必须包含 `actor_user_id`、`permission_code`、`resource_type/resource_id`、`correlation_id`、`enqueued_at`，禁止序列化 `authorized=true`。

handle 固定顺序：stale gate → 加载当前账户状态 → 重新执行“功能权限 ∧ 数据可见 ∧ 动作资格” → 领域事务。

- `AUTH_STATE_STALE`：可重试；
- `AUTH_REVOKED`、账户失效、资源越权：终止并审计，不得重试后重新获得权限；
- scheduler 使用最小权限 service principal；普通 CLI 默认拒绝，必须显式 actor；
- 依赖事务结果的任务改为 `after_commit=true` 或 `DB::afterCommit`。

验收覆盖入队后撤权、禁用、移出项目、scope 收窄及 stale/revoked 重试分类。

### V17-08 [P1] 组织图合法性和 inactive 截断语义未冻结

**设计定位**

- 第 29 行定义直接组织候选集。
- 第 83～90 行依赖组织子树。
- 第 96 行将 parent_id、org_type、is_active 定义为授权字段，却没有定义图不变量和 inactive 节点语义。

**代码依据**

- organizations.parent_id 当前只有自引用 FK，并使用 cascadeOnDelete：`ipms-backend/database/migrations/2026_08_03_000006_create_organizations_table.php:8-14`。
- 当前创建 API 只保证父节点 org_type 相同；数据库层没有无环约束：`ipms-backend/app/Http/Controllers/Api/OrganizationController.php:48-59`。

**失败场景**

直接 SQL 或未来重挂接口形成自环、两节点环或跨类型父子关系，递归查询可能得到异常范围。停用锚点或中间节点时，文档也没有说明其规则是否继续参与、后代是否仍在 org_subtree 中。

**建议修改条文**

> 组织结构是按 org_type 分区的有根森林：parent_id <> id、父子 org_type 相同、禁止任何祖先环。inactive 组织不贡献主体规则；org_subtree 仅包含从 active 锚点出发且整条路径均 active 的节点，inactive 中间节点截断全部后代。

重挂事务：取得按 org_type 固定 key 的 advisory xact lock → 锁节点和候选祖先链 → 递归检查类型及环 → 更新 parent_id → epoch 触发 → 提交。

验收覆盖自环、两节点环、跨类型父节点、并发 A→B/B→A、停用锚点、停用中间节点和重新启用。

## 5. 建议修改顺序

1. **先冻结授权一致性**：V17-04、V17-05，避免双版本与并发撤权产生错误缓存。
2. **再冻结数据模型约束**：V17-01、V17-06、V17-08，确保非法主体关系和组织图无法进入数据库。
3. **完善部署与治理状态机**：V17-02、V17-03，保证切换和恢复可逆且不可重放。
4. **补后台执行契约**：V17-07，统一 HTTP 与异步任务的当前权限判断。
5. 修订为 v1.8 后再次静态复审；实现后分别执行约束、并发缓存、A/B 发布、恢复凭证和后台撤权验收。

## 6. 批准前门槛

1. 冻结完整 module × scope_type 值域。
2. 将 resource_auth_states 改为构建级状态机，并按 build 单独激活读写闭包。
3. 给恢复凭证增加停机、过期、消费和防重放约束。
4. 将授权摘要纳入缓存键，并冻结 epoch 双读协议。
5. 建立主体类型和组织图数据库不变量。
6. 后台任务执行前重新加载身份并完成全公式授权。
7. 完成相应负向、并发和部署演练后再批准开发。

## 7. 审计边界

- 本轮未执行 v1.7 功能测试，因为该架构尚未实现。
- 现有代码和部署脚本引用只用于证明设计缺口和失败路径。
- 本次交付只新增本审计报告，不修改 v1.7 设计正文、应用代码、数据库或部署配置，不提交、不推送、不部署。
