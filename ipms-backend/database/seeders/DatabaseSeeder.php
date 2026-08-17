<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('ipms.demo_password');

        if (! is_string($password) || trim($password) === '') {
            throw new LogicException('IPMS_DEMO_PASSWORD is required before database seeding.');
        }

        DB::transaction(function (): void {
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
        });
    }
}
