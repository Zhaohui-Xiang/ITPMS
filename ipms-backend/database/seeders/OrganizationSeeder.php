<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('organizations')->insert([
            ['id' => 1, 'parent_id' => null, 'name' => '信息化部门', 'org_type' => 1, 'description' => '内部 IT 团队根节点', 'is_active' => true],
            ['id' => 2, 'parent_id' => null, 'name' => '供应商',      'org_type' => 2, 'description' => '供应商团队根节点',   'is_active' => true],
            ['id' => 3, 'parent_id' => null, 'name' => '公司',        'org_type' => 3, 'description' => '系统用户组织根节点', 'is_active' => true],
        ]);
    }
}
