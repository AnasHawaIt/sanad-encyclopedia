<?php

namespace Tests\Feature\Access;

use App\Models\ActivityLog;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesAccessFixtures;
use Tests\DatabaseTestCase;

class RoleManagementTest extends DatabaseTestCase
{
    use CreatesAccessFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
    }

    public function test_admin_lists_roles_and_grouped_permissions(): void
    {
        $admin = $this->admin();

        $roles = $this->actingAs($admin, 'web')->getJson('/api/v1/roles')->assertOk()->json('data');
        $this->assertCount(5, $roles);
        $this->assertSame(1, collect($roles)->firstWhere('name', 'admin')['users_count']);
        $this->assertTrue(collect($roles)->firstWhere('name', 'admin')['is_protected']);

        $groups = $this->actingAs($admin, 'web')->getJson('/api/v1/permissions')->assertOk()->json('data');
        $persons = collect($groups)->firstWhere('key', 'persons');
        $this->assertContains('persons.update', collect($persons['permissions'])->pluck('name')->all());
    }

    public function test_admin_creates_custom_role_with_permissions(): void
    {
        $res = $this->actingAs($this->admin(), 'web')->postJson('/api/v1/roles', [
            'name' => 'copy_editor', 'label' => 'محرر لغوي', 'permissions' => ['persons.view', 'persons.update'],
        ]);

        $res->assertCreated()->assertJsonPath('data.is_protected', false);
        $this->assertEqualsCanonicalizing(['persons.view', 'persons.update'], Role::findByName('copy_editor', 'web')->permissions->pluck('name')->all());
        $this->assertTrue(ActivityLog::where('event', 'roles.created')->exists());
    }

    public function test_invalid_role_name_duplicate_and_unknown_permission_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'web')->postJson('/api/v1/roles', ['name' => 'Bad Name', 'label' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->actingAs($admin, 'web')->postJson('/api/v1/roles', ['name' => 'reader', 'label' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->actingAs($admin, 'web')->postJson('/api/v1/roles', ['name' => 'ok_role', 'label' => 'x', 'permissions' => ['fake.perm']])
            ->assertStatus(422)->assertJsonValidationErrors('permissions.0');
    }

    public function test_update_permissions_logs_added_and_removed(): void
    {
        $role = Role::findByName('reader');

        $this->actingAs($this->admin(), 'web')->putJson("/api/v1/roles/{$role->id}", ['permissions' => ['persons.view', 'exports.create']])->assertOk();

        $this->assertTrue($role->fresh()->hasPermissionTo('exports.create'));
        $this->assertFalse($role->fresh()->hasPermissionTo('books.view'));
        $changes = ActivityLog::where('event', 'roles.updated')->firstOrFail()->changes['permissions'];
        $this->assertContains('exports.create', $changes['added']);
        $this->assertContains('books.view', $changes['removed']);
    }

    public function test_permission_change_takes_effect_for_existing_users_immediately(): void
    {
        $reader = $this->userWithRole('reader');
        $this->assertFalse($reader->can('exports.create'));

        $this->actingAs($this->admin(), 'web')
            ->putJson('/api/v1/roles/'.Role::findByName('reader')->id, ['permissions' => ['exports.create']])->assertOk();

        $this->assertTrue($reader->fresh()->can('exports.create'));
    }

    public function test_admin_role_permissions_are_locked_and_protected_roles_keep_their_name(): void
    {
        $admin = $this->admin();
        $adminRole = Role::findByName('admin');
        $reader = Role::findByName('reader');

        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$adminRole->id}", ['permissions' => ['persons.view']])->assertStatus(422);
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$reader->id}", ['name' => 'viewer'])->assertStatus(422);
        $this->assertSame('reader', $reader->fresh()->name);
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$reader->id}", ['label' => 'قارئ مسجّل'])->assertOk();
    }

    public function test_protected_and_in_use_roles_cannot_be_deleted_but_unused_custom_can(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web')->deleteJson('/api/v1/roles/'.Role::findByName('reader')->id)->assertStatus(422);

        $custom = Role::create(['name' => 'temp_role', 'label' => 'مؤقت', 'guard_name' => 'web']);
        $this->userWithRole('temp_role');
        $this->actingAs($admin, 'web')->deleteJson("/api/v1/roles/{$custom->id}")->assertStatus(422);

        $free = Role::create(['name' => 'free_role', 'label' => 'حر', 'guard_name' => 'web']);
        $this->actingAs($admin, 'web')->deleteJson("/api/v1/roles/{$free->id}")->assertNoContent();
        $this->assertNull(Role::where('name', 'free_role')->first());
    }

    public function test_roles_view_cannot_modify_and_non_admin_cannot_grant_what_it_lacks(): void
    {
        $viewer = $this->userWithRole('reader');
        $viewer->givePermissionTo('roles.view');
        $this->actingAs($viewer, 'web')->getJson('/api/v1/roles')->assertOk();
        $this->actingAs($viewer, 'web')->postJson('/api/v1/roles', ['name' => 'x_role', 'label' => 'x'])->assertForbidden();

        $manager = $this->userWithRole('reader');
        $manager->givePermissionTo(['roles.view', 'roles.manage']);
        $this->actingAs($manager, 'web')->postJson('/api/v1/roles', [
            'name' => 'sneaky', 'label' => 'x', 'permissions' => ['users.manage'],
        ])->assertForbidden();
        $this->assertNull(Role::where('name', 'sneaky')->first());
    }
}
