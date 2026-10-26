<?php

namespace Tests\Feature\Auth;

use App\Models\ActivityLog;
use App\Models\User;
use Tests\Concerns\CreatesAccessFixtures;
use Tests\DatabaseTestCase;

class AuthTest extends DatabaseTestCase
{
    use CreatesAccessFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
    }

    public function test_user_can_login_and_receives_roles_and_permissions(): void
    {
        $user = $this->userWithRole('reader', ['email' => 'a@example.test']);

        $response = $this->spa()->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'password']);

        $response->assertOk()
            ->assertJsonPath('data.email', 'a@example.test')
            ->assertJsonPath('data.roles.0.name', 'reader')
            ->assertJsonMissingPath('data.password');
        $this->assertContains('persons.view', $response->json('data.permissions'));
        $this->assertNotContains('users.manage', $response->json('data.permissions'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertTrue(ActivityLog::where('event', 'auth.login')->where('user_id', $user->id)->exists());
    }

    public function test_wrong_password_is_rejected_without_login(): void
    {
        User::factory()->create(['email' => 'a@example.test']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertGuest('web');
    }

    public function test_inactive_user_cannot_login_and_gets_same_generic_error(): void
    {
        User::factory()->create(['email' => 'off@example.test', 'is_active' => false]);

        $inactive = $this->spa()->postJson('/api/v1/auth/login', ['email' => 'off@example.test', 'password' => 'password']);
        $unknown = $this->spa()->postJson('/api/v1/auth/login', ['email' => 'none@example.test', 'password' => 'password']);

        $inactive->assertStatus(422);
        $this->assertSame($unknown->json('errors.email'), $inactive->json('errors.email'));
        $this->assertGuest('web');
    }

    public function test_soft_deleted_user_cannot_login(): void
    {
        $user = User::factory()->create(['email' => 'gone@example.test']);
        $user->delete();

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'gone@example.test', 'password' => 'password'])
            ->assertStatus(422);
        $this->assertGuest('web');
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        User::factory()->create(['email' => 'a@example.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->spa()->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'bad'])->assertStatus(422);
        }

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'password'])
            ->assertStatus(429);
        $this->assertGuest('web');
    }

    public function test_me_requires_authentication_and_returns_current_user(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);

        $user = $this->userWithRole('researcher');
        $this->actingAs($user, 'web')->spa()->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_inactive_session_is_rejected_by_middleware(): void
    {
        $user = $this->userWithRole('reader', ['is_active' => false]);

        $this->actingAs($user, 'web')->spa()->getJson('/api/v1/auth/me')->assertStatus(403);
    }

    public function test_logout_ends_the_session_and_is_logged(): void
    {
        $user = $this->userWithRole('reader');

        $this->actingAs($user, 'web')->spa()->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
        $this->assertTrue(ActivityLog::where('event', 'auth.logout')->where('user_id', $user->id)->exists());
    }
}
