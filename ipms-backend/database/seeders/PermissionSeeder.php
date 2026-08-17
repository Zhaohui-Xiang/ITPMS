<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('permissions')->insert([
            // Project management
            ['code' => 'project.create', 'name' => '创建项目', 'module' => 'project', 'action' => 'create', 'description' => '创建新的业务系统项目'],
            ['code' => 'project.edit', 'name' => '编辑项目', 'module' => 'project', 'action' => 'edit', 'description' => '编辑项目基本信息'],
            ['code' => 'project.delete', 'name' => '删除项目', 'module' => 'project', 'action' => 'delete', 'description' => '删除项目（需通过关联校验）'],
            ['code' => 'project.view', 'name' => '查看项目', 'module' => 'project', 'action' => 'view', 'description' => '查看项目列表和详情'],
            ['code' => 'project.archive', 'name' => '归档项目', 'module' => 'project', 'action' => 'transition', 'description' => '归档/取消归档项目'],
            // Requirement management
            ['code' => 'requirement.create', 'name' => '创建需求', 'module' => 'requirement', 'action' => 'create', 'description' => '提交新需求'],
            ['code' => 'requirement.edit', 'name' => '编辑需求', 'module' => 'requirement', 'action' => 'edit', 'description' => '编辑需求字段（触发版本记录）'],
            ['code' => 'requirement.delete', 'name' => '删除需求', 'module' => 'requirement', 'action' => 'delete', 'description' => '删除需求'],
            ['code' => 'requirement.view', 'name' => '查看需求', 'module' => 'requirement', 'action' => 'view', 'description' => '查看需求列表和详情'],
            ['code' => 'requirement.approve', 'name' => '审核需求', 'module' => 'requirement', 'action' => 'approve', 'description' => '审核/驳回需求'],
            ['code' => 'requirement.assign', 'name' => '分配需求', 'module' => 'requirement', 'action' => 'assign', 'description' => '分配需求至供应商'],
            ['code' => 'requirement.transition', 'name' => '状态流转', 'module' => 'requirement', 'action' => 'transition', 'description' => '推进需求状态流转'],
            // Task management
            ['code' => 'task.create', 'name' => '创建任务', 'module' => 'task', 'action' => 'create', 'description' => '在需求下创建子任务'],
            ['code' => 'task.edit', 'name' => '编辑任务', 'module' => 'task', 'action' => 'edit', 'description' => '编辑任务信息'],
            ['code' => 'task.assign', 'name' => '分配任务', 'module' => 'task', 'action' => 'assign', 'description' => '分配任务给执行人'],
            ['code' => 'task.claim', 'name' => '认领任务', 'module' => 'task', 'action' => 'transition', 'description' => '认领任务并开始执行'],
            ['code' => 'task.update_status', 'name' => '更新任务状态', 'module' => 'task', 'action' => 'transition', 'description' => '更新任务状态'],
            ['code' => 'task.view', 'name' => '查看任务', 'module' => 'task', 'action' => 'view', 'description' => '查看任务列表和详情'],
            // Defect management
            ['code' => 'defect.create', 'name' => '提交缺陷', 'module' => 'defect', 'action' => 'create', 'description' => '提交新的缺陷'],
            ['code' => 'defect.edit', 'name' => '编辑缺陷', 'module' => 'defect', 'action' => 'edit', 'description' => '编辑缺陷信息'],
            ['code' => 'defect.confirm', 'name' => '确认缺陷', 'module' => 'defect', 'action' => 'approve', 'description' => '确认缺陷有效性'],
            ['code' => 'defect.assign', 'name' => '指派缺陷', 'module' => 'defect', 'action' => 'assign', 'description' => '指派缺陷修复人'],
            ['code' => 'defect.fix', 'name' => '修复缺陷', 'module' => 'defect', 'action' => 'transition', 'description' => '修复缺陷并提交复测'],
            ['code' => 'defect.retest', 'name' => '复测缺陷', 'module' => 'defect', 'action' => 'transition', 'description' => '复测通过/不通过'],
            ['code' => 'defect.view', 'name' => '查看缺陷', 'module' => 'defect', 'action' => 'view', 'description' => '查看缺陷列表和详情'],
            // Document management
            ['code' => 'document.upload', 'name' => '上传文档', 'module' => 'document', 'action' => 'upload', 'description' => '上传文件文档'],
            ['code' => 'document.download', 'name' => '下载文档', 'module' => 'document', 'action' => 'download', 'description' => '下载文件文档'],
            ['code' => 'document.edit_api', 'name' => '编辑接口文档', 'module' => 'document', 'action' => 'edit', 'description' => '在线编辑接口文档'],
            ['code' => 'document.delete', 'name' => '删除文档', 'module' => 'document', 'action' => 'delete', 'description' => '删除文档（进入回收站）'],
            ['code' => 'document.view', 'name' => '查看文档', 'module' => 'document', 'action' => 'view', 'description' => '查看文档列表和内容'],
            // Organization & User management
            ['code' => 'organization.manage', 'name' => '管理组织架构', 'module' => 'organization', 'action' => 'edit', 'description' => '管理组织架构树'],
            ['code' => 'user.manage', 'name' => '管理用户账号', 'module' => 'organization', 'action' => 'create', 'description' => '创建/编辑/禁用用户账号'],
            ['code' => 'role.assign', 'name' => '分配角色', 'module' => 'organization', 'action' => 'assign', 'description' => '为用户分配角色'],
            // Audit log
            ['code' => 'audit.view_all', 'name' => '查看全量日志', 'module' => 'audit', 'action' => 'view', 'description' => '查看全量操作日志'],
            ['code' => 'audit.view_scoped', 'name' => '查看范围内日志', 'module' => 'audit', 'action' => 'view', 'description' => '按数据权限范围查看操作日志'],
        ]);
    }
}
