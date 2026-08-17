<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');
    }

    public function test_sanctum_csrf_cookie_is_available_for_same_origin_sessions(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_active_user_can_log_in_with_email_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'pm@example.test', 'password' => 'Secret123']);

        $this->postJson('/api/login', ['username' => 'PM@EXAMPLE.TEST', 'password' => 'Secret123'])
            ->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.must_change_password', false);
    }

    public function test_active_user_can_log_in_with_username_case_insensitively(): void
    {
        $user = User::factory()->create(['username' => 'it.pm', 'password' => 'Secret123']);

        $this->postJson('/api/login', ['username' => 'IT.PM', 'password' => 'Secret123'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_invalid_credentials_use_the_validation_error_contract(): void
    {
        User::factory()->create(['email' => 'pm@example.test', 'password' => 'Secret123']);

        $this->postJson('/api/login', ['username' => 'pm@example.test', 'password' => 'WrongPassword'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['code', 'error_code', 'message', 'errors' => ['username'], 'trace_id']);
    }

    public function test_disabled_user_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'disabled@example.test',
            'password' => 'Secret123',
            'is_disabled' => true,
        ]);

        $this->postJson('/api/login', ['username' => 'disabled@example.test', 'password' => 'Secret123'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_DISABLED');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->create([
            'username' => 'inactive.user',
            'password' => 'Secret123',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', ['username' => 'inactive.user', 'password' => 'Secret123'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_INACTIVE');

        $this->assertGuest();
    }

    public function test_current_user_and_logout_use_standard_envelopes(): void
    {
        $user = User::factory()->create(['email' => 'session@example.test', 'password' => 'Secret123']);

        $this->withHeader('Origin', 'http://localhost');
        $this->postJson('/api/login', ['username' => 'session@example.test', 'password' => 'Secret123'])
            ->assertOk();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.user.id', $user->id);

        $this->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data', null);

        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_password_update_clears_must_change_password_and_replaces_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'password@example.test',
            'password' => 'Secret123',
            'must_change_password' => true,
        ]);

        $this->withHeader('Origin', 'http://localhost');
        $this->postJson('/api/login', ['username' => 'password@example.test', 'password' => 'Secret123'])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.user.must_change_password', true);

        $this->putJson('/api/settings/password', [
            'current_password' => 'Secret123',
            'new_password' => 'NewSecret123',
            'new_password_confirmation' => 'NewSecret123',
        ])
            ->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data', null);

        $this->assertFalse($user->fresh()->must_change_password);

        $this->postJson('/api/logout')->assertOk();

        $this->postJson('/api/login', ['username' => 'password@example.test', 'password' => 'Secret123'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');

        $this->postJson('/api/login', ['username' => 'password@example.test', 'password' => 'NewSecret123'])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);
    }

    public function test_user_creation_normalizes_email_before_unique_validation(): void
    {
        User::factory()->create(['email' => 'Existing@Example.Test']);
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'username' => 'new.user',
                'password' => 'Secret123',
                'email' => 'EXISTING@EXAMPLE.TEST',
                'user_type' => UserType::SUPPLIER->value,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['email']]);

        $this->assertDatabaseMissing('users', ['username' => 'new.user']);
    }

    public function test_profile_update_normalizes_email_and_reports_case_insensitive_collision_as_validation(): void
    {
        User::factory()->create(['email' => 'Existing@Example.Test']);
        $user = User::factory()->create(['email' => 'original@example.test']);

        $this->actingAs($user)
            ->putJson('/api/settings/profile', ['email' => 'Unique@Example.TEST'])
            ->assertOk();

        $this->assertSame('unique@example.test', $user->fresh()->email);

        $this->putJson('/api/settings/profile', ['email' => 'EXISTING@EXAMPLE.TEST'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['email']]);

        $this->assertSame('unique@example.test', $user->fresh()->email);
    }

    public function test_nonempty_emails_are_case_insensitively_unique_while_empty_legacy_values_are_allowed(): void
    {
        User::factory()->create(['email' => '']);
        User::factory()->create(['email' => '']);
        User::factory()->create(['email' => 'duplicate@example.test']);

        $this->expectException(QueryException::class);

        User::factory()->create(['email' => 'DUPLICATE@EXAMPLE.TEST']);
    }
}
