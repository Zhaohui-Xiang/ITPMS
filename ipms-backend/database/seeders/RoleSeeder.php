<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('roles')->insert([
            // Internal IT roles
            ['name' => '超级管理员', 'code' => 'super_admin', 'user_type' => 1, 'description' => '拥有系统最高权限，全局数据可见', 'is_system' => true],
            ['name' => 'IT项目经理', 'code' => 'it_pm', 'user_type' => 1, 'description' => '管理所负责项目的需求全生命周期', 'is_system' => true],
            ['name' => 'IT项目成员', 'code' => 'it_member', 'user_type' => 1, 'description' => '参与具体需求的执行和协作', 'is_system' => true],
            // Supplier roles
            ['name' => '供应商项目经理', 'code' => 'supplier_pm', 'user_type' => 2, 'description' => '管理供应商侧的需求交付', 'is_system' => true],
            ['name' => '开发人员', 'code' => 'supplier_dev', 'user_type' => 2, 'description' => '承接开发任务并执行', 'is_system' => true],
            ['name' => '测试人员', 'code' => 'supplier_tester', 'user_type' => 2, 'description' => '供应商内部测试', 'is_system' => true],
            // System user role
            ['name' => '需求提出人', 'code' => 'requester', 'user_type' => 3, 'description' => '提交业务需求并参与验收', 'is_system' => true],
        ]);
    }
}
