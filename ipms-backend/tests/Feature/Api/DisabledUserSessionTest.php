<?php

namespace Tests\Feature\Api;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class DisabledUserSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('app.key', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_disabled_users_existing_session_is_rejected_and_destroyed(): void
    {
        $user = User::factory()->create(['password' => 'Secret123']);

        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Secret123'])
            ->assertOk();
        $this->getJson('/api/user')->assertOk();

        // 超管禁用后，旧会话立即失效（测试进程内 guard 缓存了用户实例，先清缓存模拟真实请求）
        $user->forceFill(['is_disabled' => true])->save();
        Auth::forgetGuards();

        $this->getJson('/api/user')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_DISABLED');

        // 会话已被销毁：后续请求按未认证处理
        Auth::forgetGuards();
        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_disabled_users_existing_session_cannot_write(): void
    {
        $user = User::factory()->withRole('requester')->create(['password' => 'Secret123']);

        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Secret123'])
            ->assertOk();

        $user->forceFill(['is_disabled' => true])->save();
        Auth::forgetGuards();

        $this->postJson('/api/requirements', [
            'title' => 'disabled session write attempt',
            'description' => 'should be rejected',
            'priority' => 3,
            'requirement_type' => 1,
            'project_ids' => [1],
        ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_DISABLED');
    }

    public function test_active_users_session_is_not_affected(): void
    {
        $user = User::factory()->create(['password' => 'Secret123']);

        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Secret123'])
            ->assertOk();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_disabled_user_still_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'password' => 'Secret123',
            'is_disabled' => true,
        ]);

        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Secret123'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_DISABLED');
    }
}
