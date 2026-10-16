<?php

namespace Tests\Feature\Access;

use App\Models\ActivityLog;
use App\Models\User;
use Tests\Concerns\CreatesAccessFixtures;
use Tests\DatabaseTestCase;

class UserManagementTest extends DatabaseTestCase
{
    use CreatesAccessFixtures;

    private const STRONG = 'Str0ngPassw0rd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'باحث جديد',
            'email' => 'new@example.test',
            'password' => self::STRONG,
            'roles' => ['researcher'],
        ], $overrides);
    }

    public function test_admin_creates_user_with_roles_and_it_is_audited_without_password(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'web')->postJson('/api/v1/users', $this->payload());

        $res->assertCreated()->assertJsonPath('data.roles.0.name', 'researcher')->assertJsonMissingPath('data.password');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertNotSame(self::STRONG, $user->password);
        $log = ActivityLog::where('event', 'users.created')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringNotContainsString(self::STRONG, json_encode($log->changes));
    }

    public function test_weak_password_duplicate_email_and_unknown_role_are_rejected(): void
    {
        $admin = $this->admin();
        User::factory()->create(['email' => 'dup@example.test']);

        $this->actingAs($admin, 'web')->postJson('/api/v1/users', $this->payload(['password' => 'short']))
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->actingAs($admin, 'web')->postJson('/api/v1/users', $this->payload(['email' => 'dup@example.test']))
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->actingAs($admin, 'web')->postJson('/api/v1/users', $this->payload(['roles' => ['nope']]))
            ->assertStatus(422)->assertJsonValidationErrors('roles.0');
    }

    public function test_users_view_permission_cannot_modify(): void
    {
        $viewer = $this->userWithRole('reader');
        $viewer->givePermissionTo('users.view');
        $target = User::factory()->create();

        $this->actingAs($viewer, 'web')->getJson('/api/v1/users')->assertOk();
        $this->actingAs($viewer, 'web')->postJson('/api/v1/users', $this->payload())->assertForbidden();
        $this->actingAs($viewer, 'web')->putJson("/api/v1/users/{$target->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer, 'web')->deleteJson("/api/v1/users/{$target->id}")->assertForbidden();
    }

    public function test_index_filters_by_search_role_and_status(): void
    {
        $admin = $this->admin(['name' => 'المدير']);
        $this->userWithRole('researcher', ['name' => 'أحمد الباحث']);
        $this->userWithRole('reader', ['name' => 'سعيد', 'is_active' => false]);
        User::factory()->create(['name' => 'محذوف'])->delete();

        $names = fn (string $qs) => collect($this->actingAs($admin, 'web')->getJson("/api/v1/users?{$qs}")->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['أحمد الباحث'], $names('q='.urlencode('أحمد')));
        $this->assertSame(['أحمد الباحث'], $names('role=researcher'));
        $this->assertSame(['سعيد'], $names('status=inactive'));
        $this->assertSame(['محذوف'], $names('status=trashed'));
    }

    public function test_update_changes_roles_and_logs_diff(): void
    {
        $admin = $this->admin();
        $user = $this->userWithRole('reader');

        $this->actingAs($admin, 'web')->putJson("/api/v1/users/{$user->id}", ['roles' => ['reviewer'], 'name' => 'اسم جديد'])
            ->assertOk()->assertJsonPath('data.roles.0.name', 'reviewer');

        $this->assertTrue($user->fresh()->hasRole('reviewer'));
        $this->assertFalse($user->fresh()->hasRole('reader'));
        $log = ActivityLog::where('event', 'users.updated')->firstOrFail();
        $this->assertSame('اسم جديد', $log->changes['name']['to']);
        $this->assertSame(['reader'], $log->changes['roles']['from']);
    }

    public function test_deactivating_revokes_tokens_and_blocks_access(): void
    {
        $admin = $this->admin();
        $user = $this->userWithRole('reader');
        $user->createToken('t');
        $this->assertSame(1, $user->tokens()->count());

        $this->actingAs($admin, 'web')->putJson("/api/v1/users/{$user->id}", ['is_active' => false])->assertOk();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_password_change_hashes_and_revokes_tokens(): void
    {
        $admin = $this->admin();
        $user = $this->userWithRole('reader');
        $user->createToken('t');

        $this->actingAs($admin, 'web')->putJson("/api/v1/users/{$user->id}", ['password' => 'An0therStrongPass'])->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('An0therStrongPass', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $changes = ActivityLog::where('event', 'users.updated')->firstOrFail()->changes;
        $this->assertTrue($changes['credentials_changed']);
        $this->assertStringNotContainsString('An0therStrongPass', json_encode($changes));
    }

    public function test_last_active_admin_cannot_be_deactivated_deleted_or_demoted(): void
    {
        $other = $this->admin();
        $only = $this->admin();
        $other->update(['is_active' => false]);

        // المدير الوحيد النشط لا يستطيع تعطيل نفسه أو حذفها
        $this->actingAs($only, 'web')->putJson("/api/v1/users/{$only->id}", ['is_active' => false])->assertStatus(422);
        $this->actingAs($only, 'web')->deleteJson("/api/v1/users/{$only->id}")->assertStatus(422);
        // ولا سحب دور المدير منه
        $this->actingAs($only, 'web')->putJson("/api/v1/users/{$only->id}", ['roles' => ['reader']])->assertStatus(422);
        $this->assertTrue($only->fresh()->hasRole('admin'));
        $this->assertTrue($only->fresh()->is_active);
    }

    public function test_admin_can_remove_another_admin_when_another_active_admin_remains(): void
    {
        $a = $this->admin();
        $b = $this->admin();

        $this->actingAs($a, 'web')->putJson("/api/v1/users/{$b->id}", ['roles' => ['reader']])->assertOk();
        $this->assertFalse($b->fresh()->hasRole('admin'));
    }

    public function test_admin_cannot_delete_self_even_with_other_admins(): void
    {
        $a = $this->admin();
        $this->admin();

        $this->actingAs($a, 'web')->deleteJson("/api/v1/users/{$a->id}")->assertStatus(422);
        $this->assertNull($a->fresh()->deleted_at);
    }

    public function test_delete_is_soft_and_restore_brings_it_back(): void
    {
        $admin = $this->admin();
        $user = $this->userWithRole('reader');

        $this->actingAs($admin, 'web')->deleteJson("/api/v1/users/{$user->id}")->assertNoContent();
        $this->assertSoftDeleted($user);
        $this->actingAs($admin, 'web')->getJson("/api/v1/users/{$user->id}")->assertNotFound();

        $this->actingAs($admin, 'web')->postJson("/api/v1/users/{$user->id}/restore")->assertOk();
        $this->assertNull($user->fresh()->deleted_at);
        $this->assertTrue(ActivityLog::where('event', 'users.restored')->exists());
    }

    public function test_non_admin_manager_cannot_touch_admins_or_grant_admin_role(): void
    {
        $admin = $this->admin();
        $manager = $this->userWithRole('reader');
        $manager->givePermissionTo(['users.view', 'users.manage']);
        $victim = $this->userWithRole('reader');

        $this->actingAs($manager, 'web')->putJson("/api/v1/users/{$admin->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($manager, 'web')->deleteJson("/api/v1/users/{$admin->id}")->assertForbidden();
        $this->actingAs($manager, 'web')->putJson("/api/v1/users/{$victim->id}", ['roles' => ['admin']])->assertForbidden();
        $this->actingAs($manager, 'web')->postJson('/api/v1/users', $this->payload(['roles' => ['admin']]))->assertForbidden();
        $this->assertFalse($victim->fresh()->hasRole('admin'));
    }
}
