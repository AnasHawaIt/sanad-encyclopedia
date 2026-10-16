<?php

namespace Tests\Feature\Access;

use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesAccessFixtures;
use Tests\DatabaseTestCase;

class ApiProtectionTest extends DatabaseTestCase
{
    use CreatesAccessFixtures;

    private const PUBLIC_URIS = ['api/v1/auth/login'];

    private function v1Routes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/') && ! str_contains($r->uri(), 'health'))
            ->reject(fn ($r) => in_array($r->uri(), self::PUBLIC_URIS, true))
            ->all();
    }

    public function test_every_v1_route_requires_sanctum_auth_and_active_account(): void
    {
        $routes = $this->v1Routes();
        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $mw = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $mw, $route->uri().' بلا مصادقة');
            $this->assertContains(\App\Http\Middleware\EnsureUserIsActive::class, $mw, $route->uri().' بلا فحص التفعيل');
        }
    }

    public function test_every_v1_route_except_session_endpoints_declares_a_permission(): void
    {
        $session = ['api/v1/auth/logout', 'api/v1/auth/me'];
        foreach ($this->v1Routes() as $route) {
            if (in_array($route->uri(), $session, true)) {
                continue;
            }
            $can = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'can:'));
            $this->assertNotNull($can, $route->uri().' بلا صلاحية');
            $permission = substr($can, 4);
            $this->assertContains($permission, app(\App\Services\Access\PermissionCatalog::class)->names(), "{$permission} غير موجودة في الكتالوج");
        }
    }

    public function test_guests_get_401_json_on_protected_endpoints(): void
    {
        $this->getJson('/api/v1/users')->assertStatus(401);
        $this->postJson('/api/v1/users', [])->assertStatus(401);
        $this->getJson('/api/v1/roles')->assertStatus(401);
        $this->getJson('/api/v1/permissions')->assertStatus(401);
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }

    public function test_reader_gets_403_on_admin_endpoints(): void
    {
        $this->seedAccess();
        $reader = $this->userWithRole('reader');

        foreach (['/api/v1/users', '/api/v1/roles', '/api/v1/permissions'] as $uri) {
            $this->actingAs($reader, 'web')->getJson($uri)->assertForbidden();
        }
    }

    public function test_api_responses_never_expose_password_or_remember_token(): void
    {
        $this->seedAccess();
        $admin = $this->admin();

        $body = $this->actingAs($admin, 'web')->getJson('/api/v1/users')->assertOk()->getContent();
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('remember_token', $body);
    }
}
