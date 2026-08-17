<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('IPMS_DEMO_PASSWORD');

        if (! is_string($password) || $password === '') {
            throw new \LogicException('IPMS_DEMO_PASSWORD is required to seed the administrator.');
        }

        $userId = DB::table('users')->insertGetId([
            'username' => 'admin',
            'password' => Hash::make($password),
            'user_type' => 1,
            'display_name' => '系统管理员',
            'first_name' => '系统',
            'last_name' => '管理员',
            'email' => 'admin@ipms.local',
            'is_active' => true,
            'is_staff' => true,
            'must_change_password' => true,
            'is_disabled' => false,
            'created_by_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Assign super admin role
        $superAdminRole = DB::table('roles')->where('code', 'super_admin')->first();
        if ($superAdminRole) {
            DB::table('role_user')->insert([
                'user_id' => $userId,
                'role_id' => $superAdminRole->id,
                'assigned_by_id' => null,
                'assigned_at' => now(),
            ]);
        }

        // Create notification config
        DB::table('notification_configs')->insert([
            'user_id' => $userId,
            'remind_enabled' => true,
            'remind_days_before' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
