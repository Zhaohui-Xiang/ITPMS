<?php

/**
 * 授权注册表（v1.8 §2.8）— 所有 auth:sanctum 路由的单一事实源。
 *
 * 每条路由键为 "METHOD /api/uri"，字段：
 * - ability: 主权限码或 Gate 能力名（列表/详情按资源读能力）
 * - phase: 'A' = Phase A 现有语义直接生效；'deferred' = 授权由责任 Policy 方法裁决
 * - route_models: 路由参数 id → 模型类
 * - body_models: 请求体写入的模型类
 * - ability_args: Gate 参数来源说明（route/body 字段名）
 * - outputs: 响应资源（字段级闭包的挂载点）
 * - channel: http（Phase A 仅 HTTP；queue/cli 在 Phase B 登记）
 * - auth_only: 仅要求登录态（无资源授权语义）
 * - non_overridable: 2.3 不可配置覆盖清单（发布门禁/缺陷复测/版本负责人/强制发布）
 * - policy / policy_test: deferred 条目的责任 Policy 方法与覆盖它的测试
 */

return [
    // Phase A 默认关闭：hasPermission 走历史 permission_role 单源路径（行为等价）；
    // Phase B 起随 resource_auth_builds 状态机逐资源激活双源解析
    'dual_source' => false,

    'routes' => [
        // ---- 认证会话 ----
        'GET /api/user' => [
            'ability' => null, 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'AuthUser', 'channel' => 'http', 'auth_only' => true,
        ],
        'POST /api/logout' => [
            'ability' => null, 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => null, 'channel' => 'http', 'auth_only' => true,
        ],

        // ---- 工作台（行级 Scope 过滤，无单独能力） ----
        'GET /api/dashboard/summary' => [
            'ability' => 'dashboard.view', 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'DashboardSummary', 'channel' => 'http', 'auth_only' => true,
        ],
        'GET /api/dashboard/stats' => [
            'ability' => 'dashboard.view', 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'DashboardStats', 'channel' => 'http', 'auth_only' => true,
        ],
        'GET /api/dashboard/recent-requirements' => [
            'ability' => 'dashboard.view', 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'RequirementResource', 'channel' => 'http', 'auth_only' => true,
        ],
        'GET /api/dashboard/recent-tasks' => [
            'ability' => 'dashboard.view', 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'TaskResource', 'channel' => 'http', 'auth_only' => true,
        ],

        // ---- 项目 ----
        'GET /api/projects' => [
            'ability' => 'project.view', 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'ProjectResource', 'channel' => 'http',
        ],
        'POST /api/projects' => [
            'ability' => 'project.create', 'phase' => 'deferred',
            'route_models' => [], 'body_models' => ['App\Models\Project'],
            'ability_args' => 'body', 'outputs' => 'ProjectResource', 'channel' => 'http',
            'policy' => 'App\Http\Requests\StoreProjectRequest@authorize',
            'policy_test' => 'CoreAllowedActionsTest::test_project_actions_are_role_and_assignment_scoped',
        ],
        'GET /api/projects/{id}' => [
            'ability' => 'view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Project'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'ProjectResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'CoreAllowedActionsTest::test_project_actions_are_role_and_assignment_scoped',
        ],
        'PUT /api/projects/{id}' => [
            'ability' => 'update', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Project'], 'body_models' => ['App\Models\Project'],
            'ability_args' => 'route:id', 'outputs' => 'ProjectResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@update',
            'policy_test' => 'ProjectIndexContractTest',
        ],
        'DELETE /api/projects/{id}' => [
            'ability' => 'delete', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Project'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => null, 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@delete',
            'policy_test' => 'CoreAllowedActionsTest::test_project_actions_are_role_and_assignment_scoped',
        ],
        'POST /api/projects/{id}/archive' => [
            'ability' => 'archive', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Project'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'ProjectResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@archive',
            'policy_test' => 'CoreAllowedActionsTest::test_project_actions_are_role_and_assignment_scoped',
        ],
        'GET /api/projects/{id}/members' => [
            'ability' => 'manageMembers', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Project'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'ProjectMemberResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@manageMembers',
            'policy_test' => 'ProjectMembershipTest',
        ],
        'GET /api/projects/{id}/member-options' => [
            'ability' => 'manageMembers', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Project'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'UserSummary', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@manageMembers',
            'policy_test' => 'ProjectMembershipTest',
        ],
        'POST /api/projects/{id}/members' => [
            'ability' => 'manage_members', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Project'], 'body_models' => ['App\Models\ProjectMember'],
            'ability_args' => 'route:id', 'outputs' => 'ProjectMemberResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@manageMembers',
            'policy_test' => 'ProjectMembershipTest::test_only_admin_and_assigned_it_pm_can_manage_members',
        ],
        'DELETE /api/projects/{id}/members/{userId}' => [
            'ability' => 'manage_members', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Project'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => null, 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@manageMembers',
            'policy_test' => 'ProjectMembershipTest::test_only_admin_and_assigned_it_pm_can_manage_members',
        ],
        'GET /api/projects/{id}/assignee-options' => [
            'ability' => 'view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Project'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'UserSummary', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'ProjectWorkOptionsTest',
        ],
        'GET /api/projects/{projectId}/documents' => [
            'ability' => 'document.view', 'phase' => 'deferred',
            'route_models' => ['projectId' => 'App\Models\Project'], 'body_models' => [],
            'ability_args' => 'route:projectId', 'outputs' => 'DocumentResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'DocumentPermissionTest',
        ],
        'POST /api/projects/{projectId}/documents/upload' => [
            'ability' => 'document.upload', 'phase' => 'deferred',
            'route_models' => ['projectId' => 'App\Models\Project'], 'body_models' => ['App\Models\Document'],
            'ability_args' => 'route:projectId', 'outputs' => 'DocumentResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'DocumentOperationsTest',
        ],
        'POST /api/projects/{projectId}/documents/folder' => [
            'ability' => 'document.upload', 'phase' => 'deferred',
            'route_models' => ['projectId' => 'App\Models\Project'], 'body_models' => ['App\Models\Folder'],
            'ability_args' => 'route:projectId', 'outputs' => 'FolderResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'DocumentOperationsTest',
        ],
        'GET /api/projects/{projectId}/api-docs' => [
            'ability' => 'document.view', 'phase' => 'deferred',
            'route_models' => ['projectId' => 'App\Models\Project'], 'body_models' => [],
            'ability_args' => 'route:projectId', 'outputs' => 'ApiDocumentResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'DocumentPermissionTest',
        ],
        'POST /api/projects/{projectId}/api-docs' => [
            'ability' => 'document.edit_api', 'phase' => 'deferred',
            'route_models' => ['projectId' => 'App\Models\Project'], 'body_models' => ['App\Models\ApiDocument'],
            'ability_args' => 'route:projectId', 'outputs' => 'ApiDocumentResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'DocumentPermissionTest',
        ],
        'GET /api/projects/{projectId}/versions' => [
            'ability' => 'project_version.view', 'phase' => 'deferred',
            'route_models' => ['projectId' => 'App\Models\Project'], 'body_models' => [],
            'ability_args' => 'route:projectId', 'outputs' => 'ProjectVersionResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'ProjectVersionApiTest',
        ],
        'POST /api/projects/{projectId}/versions' => [
            'ability' => 'project_version.create', 'phase' => 'deferred',
            'route_models' => ['projectId' => 'App\Models\Project'], 'body_models' => ['App\Models\ProjectVersion'],
            'ability_args' => 'route:projectId', 'outputs' => 'ProjectVersionResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectVersionPolicy@create',
            'policy_test' => 'ProjectVersionApiTest::test_manager_can_create_list_and_read_project_version',
        ],

        // ---- 项目版本 ----
        'GET /api/project-versions/{id}' => [
            'ability' => 'project_version.view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\ProjectVersion'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'ProjectVersionResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectVersionPolicy@view',
            'policy_test' => 'ProjectVersionApiTest',
        ],
        'PUT /api/project-versions/{id}' => [
            'ability' => 'project_version.edit', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\ProjectVersion'], 'body_models' => ['App\Models\ProjectVersion'],
            'ability_args' => 'route:id', 'outputs' => 'ProjectVersionResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectVersionPolicy@update',
            'policy_test' => 'ProjectVersionApiTest',
        ],
        'DELETE /api/project-versions/{id}' => [
            'ability' => 'project_version.edit', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\ProjectVersion'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => null, 'channel' => 'http',
            'policy' => 'App\Policies\ProjectVersionPolicy@delete',
            'policy_test' => 'ProjectVersionApiTest',
        ],
        'POST /api/project-versions/{id}/status' => [
            'ability' => 'project_version.transition', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\ProjectVersion'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'ProjectVersionResource', 'channel' => 'http',
            'non_overridable' => true, // 发布门禁完整性不可配置覆盖
            'policy' => 'App\Policies\ProjectVersionPolicy@transition',
            'policy_test' => 'ProjectVersionApiTest',
        ],
        'GET /api/project-versions/{id}/gate-check' => [
            'ability' => 'project_version.view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\ProjectVersion'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'ReleaseGateResult', 'channel' => 'http',
            'non_overridable' => true, // 门禁全量数据计算
            'policy' => 'App\Policies\ProjectVersionPolicy@view',
            'policy_test' => 'ProjectVersionApiTest',
        ],
        'POST /api/project-versions/{id}/release' => [
            'ability' => 'project_version.release', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\ProjectVersion'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'ProjectVersionResource', 'channel' => 'http',
            'non_overridable' => true, // 强制发布例外流程独立带原因入审计
            'policy' => 'App\Policies\ProjectVersionPolicy@release',
            'policy_test' => 'ProjectReleaseServiceTest',
        ],
        'GET /api/project-versions/{id}/history' => [
            'ability' => 'project_version.view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\ProjectVersion'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'ProjectVersionHistoryResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectVersionPolicy@view',
            'policy_test' => 'ProjectVersionApiTest',
        ],

        // ---- 需求 ----
        'GET /api/requirements' => [
            'ability' => 'requirement.view', 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'RequirementResource', 'channel' => 'http',
        ],
        'GET /api/requirements/project-options' => [
            'ability' => 'requirement.view', 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'ProjectSummary', 'channel' => 'http',
        ],
        'POST /api/requirements' => [
            'ability' => 'requirement.create', 'phase' => 'deferred',
            'route_models' => [], 'body_models' => ['App\Models\Requirement'],
            'ability_args' => 'body', 'outputs' => 'RequirementResource', 'channel' => 'http',
            'policy' => 'App\Policies\RequirementPolicy@create',
            'policy_test' => 'CoreAllowedActionsTest',
        ],
        'GET /api/requirements/{id}' => [
            'ability' => 'view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Requirement'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'RequirementResource', 'channel' => 'http',
            'policy' => 'App\Policies\RequirementPolicy@view',
            'policy_test' => 'CoreAllowedActionsTest',
        ],
        'PUT /api/requirements/{id}' => [
            'ability' => 'update', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Requirement'], 'body_models' => ['App\Models\Requirement'],
            'ability_args' => 'route:id', 'outputs' => 'RequirementResource', 'channel' => 'http',
            'policy' => 'App\Policies\RequirementPolicy@update',
            'policy_test' => 'CoreAllowedActionsTest::test_pending_and_rejected_requirement_actions_match_the_workflow',
        ],
        'POST /api/requirements/{id}/review' => [
            'ability' => 'approve', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Requirement'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'RequirementResource', 'channel' => 'http',
            'policy' => 'App\Policies\RequirementPolicy@approve',
            'policy_test' => 'CoreAllowedActionsTest::test_pending_and_rejected_requirement_actions_match_the_workflow',
        ],
        'POST /api/requirements/{id}/resubmit' => [
            'ability' => 'update', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Requirement'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'RequirementResource', 'channel' => 'http',
            'policy' => 'App\Policies\RequirementPolicy@update',
            'policy_test' => 'CoreAllowedActionsTest',
        ],
        'POST /api/requirements/{id}/status' => [
            'ability' => 'transition', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Requirement'], 'body_models' => [],
            'ability_args' => 'route:id+body:project_id', 'outputs' => 'RequirementResource', 'channel' => 'http',
            'policy' => 'App\Policies\RequirementPolicy@transition',
            'policy_test' => 'CoreAllowedActionsTest::test_requester_cannot_advance_project_delivery_status',
        ],
        'GET /api/requirements/{id}/execution-owner-options' => [
            'ability' => 'assign', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Requirement'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'UserSummary', 'channel' => 'http',
            'policy' => 'App\Policies\RequirementPolicy@assign',
            'policy_test' => 'RequirementExecutionOwnerTest',
        ],
        'GET /api/requirements/{id}/versions' => [
            'ability' => 'view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Requirement'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'RequirementVersionResource', 'channel' => 'http',
            'policy' => 'App\Policies\RequirementPolicy@view',
            'policy_test' => 'CoreAllowedActionsTest',
        ],
        'GET /api/requirements/{id}/versions/{vid}' => [
            'ability' => 'view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Requirement'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'RequirementVersionResource', 'channel' => 'http',
            'policy' => 'App\Policies\RequirementPolicy@view',
            'policy_test' => 'CoreAllowedActionsTest',
        ],
        'POST /api/requirements/{id}/tasks' => [
            'ability' => 'createTask', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Requirement'], 'body_models' => ['App\Models\Task'],
            'ability_args' => 'route:id+body:project_id', 'outputs' => 'TaskResource', 'channel' => 'http',
            'policy' => 'App\Policies\RequirementPolicy@createTask',
            'policy_test' => 'CoreAllowedActionsTest',
        ],
        'PUT /api/requirements/{requirementId}/projects/{projectId}/version' => [
            'ability' => 'assignRequirement', 'phase' => 'deferred',
            'route_models' => ['requirementId' => 'App\Models\Requirement', 'projectId' => 'App\Models\Project'],
            'body_models' => ['App\Models\RequirementProject'],
            'ability_args' => 'route:requirementId+projectId', 'outputs' => 'RequirementResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectVersionPolicy@viewProject',
            'policy_test' => 'ProjectVersionApiTest::test_unplanned_scope_requires_project_and_other_manager_is_forbidden',
        ],
        'DELETE /api/requirements/{requirementId}/projects/{projectId}/version' => [
            'ability' => 'unassignRequirement', 'phase' => 'deferred',
            'route_models' => ['requirementId' => 'App\Models\Requirement', 'projectId' => 'App\Models\Project'],
            'body_models' => [],
            'ability_args' => 'route:requirementId+projectId', 'outputs' => 'RequirementResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectVersionPolicy@viewProject',
            'policy_test' => 'ProjectVersionApiTest',
        ],

        // ---- 任务 ----
        'GET /api/tasks' => [
            'ability' => 'task.view', 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'TaskResource', 'channel' => 'http',
        ],
        'POST /api/tasks' => [
            'ability' => 'createForProject', 'phase' => 'deferred',
            'route_models' => [], 'body_models' => ['App\Models\Task'],
            'ability_args' => 'body:project_id', 'outputs' => 'TaskResource', 'channel' => 'http',
            'policy' => 'App\Policies\TaskPolicy@createForProject',
            'policy_test' => 'CoreAllowedActionsTest::test_hidden_actions_are_rejected_before_validation',
        ],
        'GET /api/tasks/{id}' => [
            'ability' => 'view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Task'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'TaskResource', 'channel' => 'http',
            'policy' => 'App\Policies\TaskPolicy@view',
            'policy_test' => 'CoreAllowedActionsTest::test_task_actions_are_role_state_and_assignee_scoped',
        ],
        'PUT /api/tasks/{id}' => [
            'ability' => 'update', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Task'], 'body_models' => ['App\Models\Task'],
            'ability_args' => 'route:id', 'outputs' => 'TaskResource', 'channel' => 'http',
            'policy' => 'App\Policies\TaskPolicy@update',
            'policy_test' => 'CoreAllowedActionsTest::test_task_reassignment_requires_the_assign_permission',
        ],
        'POST /api/tasks/{id}/claim' => [
            'ability' => 'claim', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Task'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'TaskResource', 'channel' => 'http',
            'policy' => 'App\Policies\TaskPolicy@claim',
            'policy_test' => 'CoreAllowedActionsTest::test_task_actions_are_role_state_and_assignee_scoped',
        ],
        'POST /api/tasks/{id}/status' => [
            'ability' => 'transition', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Task'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'TaskResource', 'channel' => 'http',
            'policy' => 'App\Policies\TaskPolicy@transition',
            'policy_test' => 'CoreAllowedActionsTest',
        ],
        'POST /api/tasks/{id}/hold' => [
            'ability' => 'hold', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Task'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'TaskResource', 'channel' => 'http',
            'policy' => 'App\Policies\TaskPolicy@hold',
            'policy_test' => 'CoreAllowedActionsTest',
        ],

        // ---- 缺陷 ----
        'GET /api/defects' => [
            'ability' => 'defect.view', 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'DefectResource', 'channel' => 'http',
        ],
        'POST /api/defects' => [
            'ability' => 'createForRequirement', 'phase' => 'deferred',
            'route_models' => [], 'body_models' => ['App\Models\Defect'],
            'ability_args' => 'body:requirement_id+project_id', 'outputs' => 'DefectResource', 'channel' => 'http',
            'policy' => 'App\Policies\DefectPolicy@createForRequirement',
            'policy_test' => 'CoreAllowedActionsTest::test_requester_cannot_create_a_defect_for_another_requesters_requirement',
        ],
        'GET /api/defects/{id}' => [
            'ability' => 'view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Defect'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'DefectResource', 'channel' => 'http',
            'policy' => 'App\Policies\DefectPolicy@view',
            'policy_test' => 'CoreAllowedActionsTest::test_defect_actions_are_role_state_and_assignee_scoped',
        ],
        'PUT /api/defects/{id}' => [
            'ability' => 'update', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Defect'], 'body_models' => ['App\Models\Defect'],
            'ability_args' => 'route:id', 'outputs' => 'DefectResource', 'channel' => 'http',
            'policy' => 'App\Policies\DefectPolicy@update',
            'policy_test' => 'CoreAllowedActionsTest',
        ],
        'POST /api/defects/{id}/confirm' => [
            'ability' => 'confirm', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Defect'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'DefectResource', 'channel' => 'http',
            'policy' => 'App\Policies\DefectPolicy@confirm',
            'policy_test' => 'CoreAllowedActionsTest::test_defect_actions_are_role_state_and_assignee_scoped',
        ],
        'POST /api/defects/{id}/assign' => [
            'ability' => 'assign', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Defect'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'DefectResource', 'channel' => 'http',
            'policy' => 'App\Policies\DefectPolicy@assign',
            'policy_test' => 'CoreAllowedActionsTest',
        ],
        'POST /api/defects/{id}/resolve' => [
            'ability' => 'resolve', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Defect'], 'body_models' => ['App\Models\Defect'],
            'ability_args' => 'route:id', 'outputs' => 'DefectResource', 'channel' => 'http',
            'policy' => 'App\Policies\DefectPolicy@resolve',
            'policy_test' => 'CoreAllowedActionsTest',
        ],
        'POST /api/defects/{id}/verify' => [
            'ability' => 'verify', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Defect'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'DefectResource', 'channel' => 'http',
            'non_overridable' => true, // 缺陷复测资格不可配置覆盖
            'policy' => 'App\Policies\DefectPolicy@verify',
            'policy_test' => 'DefectRetestPermissionTest',
        ],
        'POST /api/defects/{id}/reopen' => [
            'ability' => 'reopen', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Defect'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'DefectResource', 'channel' => 'http',
            'non_overridable' => true,
            'policy' => 'App\Policies\DefectPolicy@reopen',
            'policy_test' => 'DefectRetestPermissionTest',
        ],
        'POST /api/defects/{id}/attachments' => [
            'ability' => 'uploadAttachment', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Defect'], 'body_models' => ['App\Models\DefectAttachment'],
            'ability_args' => 'route:id', 'outputs' => 'DefectAttachmentResource', 'channel' => 'http',
            'policy' => 'App\Policies\DefectPolicy@uploadAttachment',
            'policy_test' => 'DefectAttachmentTest',
        ],
        'GET /api/defect-attachments/{id}/download' => [
            'ability' => 'view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\DefectAttachment'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'binary', 'channel' => 'http',
            'policy' => 'App\Policies\DefectPolicy@view',
            'policy_test' => 'DefectAttachmentTest',
        ],
        'DELETE /api/defect-attachments/{id}' => [
            'ability' => 'deleteAttachment', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\DefectAttachment'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => null, 'channel' => 'http',
            'policy' => 'App\Policies\DefectPolicy@deleteAttachment',
            'policy_test' => 'DefectAttachmentTest',
        ],

        // ---- 文档（独立） ----
        'GET /api/documents/{id}/download' => [
            'ability' => 'document.download', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Document'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'binary', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'DocumentOperationsTest',
        ],
        'DELETE /api/documents/{id}' => [
            'ability' => 'document.delete', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Document'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => null, 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'DocumentOperationsTest',
        ],

        // ---- API 文档（独立） ----
        'GET /api/api-docs/{id}' => [
            'ability' => 'document.view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\ApiDocument'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'ApiDocumentResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'DocumentPermissionTest',
        ],
        'PUT /api/api-docs/{id}' => [
            'ability' => 'document.edit_api', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\ApiDocument'], 'body_models' => ['App\Models\ApiDocument'],
            'ability_args' => 'route:id', 'outputs' => 'ApiDocumentResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'DocumentPermissionTest',
        ],
        'GET /api/api-docs/{id}/versions' => [
            'ability' => 'document.view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\ApiDocument'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'ApiDocumentVersionResource', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'DocumentPermissionTest',
        ],
        'POST /api/api-docs/{id}/export' => [
            'ability' => 'document.view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\ApiDocument'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'binary', 'channel' => 'http',
            'policy' => 'App\Policies\ProjectPolicy@view',
            'policy_test' => 'DocumentPermissionTest',
        ],

        // ---- 组织 ----
        'GET /api/organizations' => [
            'ability' => 'organization.manage', 'phase' => 'deferred',
            'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'OrganizationNode', 'channel' => 'http',
            'policy' => 'App\Http\Controllers\Api\OrganizationController@authorizeAdmin',
            'policy_test' => 'OrganizationOperationsTest',
        ],
        'GET /api/organizations/{id}/children' => [
            'ability' => 'organization.manage', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Organization'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'OrganizationNode', 'channel' => 'http',
            'policy' => 'App\Http\Controllers\Api\OrganizationController@authorizeAdmin',
            'policy_test' => 'OrganizationOperationsTest',
        ],
        'POST /api/organizations' => [
            'ability' => 'organization.manage', 'phase' => 'deferred',
            'route_models' => [], 'body_models' => ['App\Models\Organization'],
            'ability_args' => 'body', 'outputs' => 'OrganizationNode', 'channel' => 'http',
            'policy' => 'App\Http\Controllers\Api\OrganizationController@authorizeAdmin',
            'policy_test' => 'OrganizationOperationsTest',
        ],
        'PUT /api/organizations/{id}' => [
            'ability' => 'organization.manage', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Organization'], 'body_models' => ['App\Models\Organization'],
            'ability_args' => 'route:id', 'outputs' => 'OrganizationNode', 'channel' => 'http',
            'policy' => 'App\Http\Controllers\Api\OrganizationController@authorizeAdmin',
            'policy_test' => 'OrganizationOperationsTest',
        ],
        'DELETE /api/organizations/{id}' => [
            'ability' => 'organization.manage', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Organization'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => null, 'channel' => 'http',
            'policy' => 'App\Http\Controllers\Api\OrganizationController@authorizeAdmin',
            'policy_test' => 'OrganizationOperationsTest',
        ],
        'POST /api/organizations/{id}/users' => [
            'ability' => 'organization.manage', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Organization'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => null, 'channel' => 'http',
            'policy' => 'App\Http\Controllers\Api\OrganizationController@authorizeAdmin',
            'policy_test' => 'OrganizationOperationsTest',
        ],
        'DELETE /api/organizations/{id}/users/{userId}' => [
            'ability' => 'organization.manage', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\Organization'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => null, 'channel' => 'http',
            'policy' => 'App\Http\Controllers\Api\OrganizationController@authorizeAdmin',
            'policy_test' => 'OrganizationOperationsTest',
        ],

        // ---- 审计日志 ----
        'GET /api/audit-logs' => [
            'ability' => 'audit.view_scoped', 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'AuditLogRow', 'channel' => 'http',
        ],
        'GET /api/audit-logs/export' => [
            'ability' => 'audit.view_scoped', 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'csv', 'channel' => 'http',
        ],

        // ---- 用户 ----
        'GET /api/users' => [
            'ability' => 'user.manage', 'phase' => 'deferred',
            'route_models' => [], 'body_models' => [],
            'ability_args' => null, 'outputs' => 'UserResource', 'channel' => 'http',
            'policy' => 'App\Http\Controllers\Api\UserController@index',
            'policy_test' => 'UserManagementScopeTest',
        ],
        'POST /api/users' => [
            'ability' => 'user.manage', 'phase' => 'deferred',
            'route_models' => [], 'body_models' => ['App\Models\User'],
            'ability_args' => 'body', 'outputs' => 'UserResource', 'channel' => 'http',
            'policy' => 'App\Http\Controllers\Api\UserController@store',
            'policy_test' => 'UserManagementScopeTest',
        ],
        'GET /api/users/{id}' => [
            'ability' => 'user.view', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\User'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => 'UserResource', 'channel' => 'http',
            'policy' => 'App\Http\Controllers\Api\UserController@show',
            'policy_test' => 'UserManagementScopeTest',
        ],
        'PUT /api/users/{id}' => [
            'ability' => 'user.manage', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\User'], 'body_models' => ['App\Models\User'],
            'ability_args' => 'route:id', 'outputs' => 'UserResource', 'channel' => 'http',
            'policy' => 'App\Http\Requests\UpdateUserRequest@authorize',
            'policy_test' => 'UserManagementScopeTest',
        ],
        'POST /api/users/{id}/disable' => [
            'ability' => 'user.disable', 'phase' => 'deferred',
            'route_models' => ['id' => 'App\Models\User'], 'body_models' => [],
            'ability_args' => 'route:id', 'outputs' => null, 'channel' => 'http',
            'non_overridable' => true, // 最后超管保护路径
            'policy' => 'App\Http\Controllers\Api\UserController@disable',
            'policy_test' => 'UserManagementScopeTest',
        ],

        // ---- 个人设置 ----
        'PUT /api/settings/profile' => [
            'ability' => null, 'phase' => 'A', 'route_models' => [], 'body_models' => ['App\Models\User'],
            'ability_args' => 'self', 'outputs' => 'UserResource', 'channel' => 'http', 'auth_only' => true,
        ],
        'PUT /api/settings/password' => [
            'ability' => null, 'phase' => 'A', 'route_models' => [], 'body_models' => ['App\Models\User'],
            'ability_args' => 'self', 'outputs' => null, 'channel' => 'http', 'auth_only' => true,
        ],

        // ---- 站内通知 ----
        'GET /api/notifications' => [
            'ability' => null, 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => 'self', 'outputs' => 'InAppNotificationResource', 'channel' => 'http', 'auth_only' => true,
        ],
        'GET /api/notifications/unread-count' => [
            'ability' => null, 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => 'self', 'outputs' => null, 'channel' => 'http', 'auth_only' => true,
        ],
        'POST /api/notifications/read-all' => [
            'ability' => null, 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => 'self', 'outputs' => null, 'channel' => 'http', 'auth_only' => true,
        ],
        'POST /api/notifications/{id}/read' => [
            'ability' => null, 'phase' => 'A',
            'route_models' => ['id' => 'App\Models\InAppNotification'], 'body_models' => [],
            'ability_args' => 'self', 'outputs' => 'InAppNotificationResource', 'channel' => 'http', 'auth_only' => true,
        ],
        'GET /api/notification-configs' => [
            'ability' => null, 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => 'self', 'outputs' => 'NotificationConfig', 'channel' => 'http', 'auth_only' => true,
        ],
        'PUT /api/notification-configs' => [
            'ability' => null, 'phase' => 'A', 'route_models' => [], 'body_models' => ['App\Models\NotificationConfig'],
            'ability_args' => 'self', 'outputs' => 'NotificationConfig', 'channel' => 'http', 'auth_only' => true,
        ],
        'GET /api/notification-logs' => [
            'ability' => null, 'phase' => 'A', 'route_models' => [], 'body_models' => [],
            'ability_args' => 'self', 'outputs' => 'NotificationLog', 'channel' => 'http', 'auth_only' => true,
        ],
    ],
];
