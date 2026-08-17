<?php

namespace Database\Seeders;

use App\Support\DemoSeedGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

class AdminUserSeeder extends Seeder
{
    private const SEED_MARKER = 'ipms:admin:v1';

    public function run(): void
    {
        DemoSeedGuard::assertProductionAuthorized();

        $password = config('ipms.demo_password');

        if (! is_string($password) || trim($password) === '') {
            throw new LogicException('IPMS_DEMO_PASSWORD is required to seed the administrator.');
        }

        DB::transaction(function () use ($password): void {
            $existing = DB::table('users')
                ->whereRaw('LOWER(username) = ?', ['admin'])
                ->first();

            if ($existing !== null && $existing->seed_marker !== self::SEED_MARKER) {
                throw new LogicException('Reserved admin username marker collision.');
            }

            $roleId = DB::table('roles')->where('code', 'super_admin')->value('id');

            if ($roleId === null) {
                throw new LogicException('The super_admin role is required to seed the administrator.');
            }

            if ($existing === null) {
                if (DB::table('users')->whereRaw('LOWER(email) = ?', ['admin@ipms.local'])->exists()) {
                    throw new LogicException('Reserved admin email collision.');
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
                    'seed_marker' => self::SEED_MARKER,
                    'created_by_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $userId = $existing->id;
            }

            if (! DB::table('role_user')->where([
                'user_id' => $userId,
                'role_id' => $roleId,
            ])->exists()) {
                DB::table('role_user')->insert([
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'assigned_by_id' => null,
                    'assigned_at' => now(),
                ]);
            }

            if (! DB::table('notification_configs')->where('user_id', $userId)->exists()) {
                DB::table('notification_configs')->insert([
                    'user_id' => $userId,
                    'remind_enabled' => true,
                    'remind_days_before' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
