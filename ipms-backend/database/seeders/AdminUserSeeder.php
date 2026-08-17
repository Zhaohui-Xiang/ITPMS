<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('ipms.demo_password');

        if (! is_string($password) || trim($password) === '') {
            throw new LogicException('IPMS_DEMO_PASSWORD is required to seed the administrator.');
        }

        DB::transaction(function () use ($password): void {
            DB::table('users')->updateOrInsert(
                ['username' => 'admin'],
                [
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
                    'updated_at' => now(),
                ],
            );

            $userId = DB::table('users')->where('username', 'admin')->value('id');
            $roleId = DB::table('roles')->where('code', 'super_admin')->value('id');

            if ($roleId === null) {
                throw new LogicException('The super_admin role is required to seed the administrator.');
            }

            DB::table('role_user')->updateOrInsert(
                ['user_id' => $userId, 'role_id' => $roleId],
                ['assigned_by_id' => null, 'assigned_at' => now()],
            );

            DB::table('notification_configs')->updateOrInsert(
                ['user_id' => $userId],
                ['remind_enabled' => true, 'remind_days_before' => 1, 'updated_at' => now()],
            );
        });
    }
}
