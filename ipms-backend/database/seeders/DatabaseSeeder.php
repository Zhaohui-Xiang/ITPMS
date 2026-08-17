<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            RolePermissionSeeder::class,
            OrganizationSeeder::class,
            AdminUserSeeder::class,
        ]);

        if (config('ipms.seed_demo')) {
            $this->call(DemoWorkflowSeeder::class);
        }
    }
}
