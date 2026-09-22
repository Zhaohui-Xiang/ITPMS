<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ForbiddenEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_abort_based_forbidden_uses_unified_error_envelope(): void
    {
        $requester = User::factory()->withRole('requester')->create();
        $itPm = User::factory()->withRole('it_pm')->create();

        foreach ([$requester, $itPm] as $actor) {
            $response = $this->actingAs($actor)->getJson('/api/organizations');
            $response->assertForbidden()
                ->assertJsonPath('code', 403)
                ->assertJsonPath('error_code', 'FORBIDDEN');
            $this->assertNotEmpty($response->json('trace_id'));
            $this->assertNotEmpty($response->json('message'));
        }
    }

    public function test_controller_inline_forbidden_uses_unified_error_envelope(): void
    {
        $requester = User::factory()->withRole('requester')->create();
        $itMember = User::factory()->withRole('it_member')->create();

        foreach ([$requester, $itMember] as $actor) {
            $response = $this->actingAs($actor)->getJson('/api/users');
            $response->assertForbidden()
                ->assertJsonPath('code', 403)
                ->assertJsonPath('error_code', 'FORBIDDEN');
            $this->assertNotEmpty($response->json('trace_id'));
        }
    }

    public function test_permission_middleware_forbidden_uses_unified_error_envelope(): void
    {
        $request = \Illuminate\Http\Request::create('/api/users', 'GET');
        $request->setUserResolver(fn () => User::factory()->withRole('requester')->create());

        $response = (new \App\Http\Middleware\CheckPermission())->handle(
            $request,
            fn () => new \Illuminate\Http\Response('ok'),
            'user.view',
        );

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertSame('FORBIDDEN', $payload['error_code']);
        $this->assertSame('user.view', $payload['errors']['required_permission']);
    }

    public function test_super_admin_keeps_access_after_envelope_fix(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->getJson('/api/organizations')->assertOk();
        $this->actingAs($admin)->getJson('/api/users')->assertOk();
    }
}
