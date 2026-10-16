<?php

namespace Tests\Feature\Access;

use App\Services\Access\PermissionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesAccessFixtures;
use Tests\DatabaseTestCase;

class RolesAndPermissionsSeederTest extends DatabaseTestCase
{
    use CreatesAccessFixtures;

    public function test_catalog_names_follow_group_dot_action_and_are_unique(): void
    {
        $names = app(PermissionCatalog::class)->names();

        $this->assertNotEmpty($names);
        $this->assertSame($names, array_values(array_unique($names)));
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $name);
        }
    }

    public function test_every_default_role_permission_exists_in_the_catalog(): void
    {
        $catalog = app(PermissionCatalog::class);
        foreach (array_keys(config('access.roles')) as $role) {
            $this->assertSame([], array_diff($catalog->defaultPermissionsFor($role), $catalog->names()), "الدور {$role}");
        }
    }

    public function test_seeder_creates_permissions_and_five_roles_with_labels(): void
    {
        $this->seedAccess();

        $this->assertSame(count(app(PermissionCatalog::class)->names()), Permission::count());
        $this->assertEqualsCanonicalizing(
            ['admin', 'researcher', 'reviewer', 'publisher', 'reader'],
            Role::pluck('name')->all()
        );
        $this->assertSame('مدير النظام', Role::findByName('admin')->label);
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('users.manage'));
        $this->assertFalse(Role::findByName('reader')->hasPermissionTo('records.view_unpublished'));
        $this->assertTrue(Role::findByName('researcher')->hasPermissionTo('records.view_unpublished'));
    }

    public function test_seeder_is_idempotent_and_keeps_customised_roles(): void
    {
        $this->seedAccess();
        $reader = Role::findByName('reader');
        $reader->revokePermissionTo('persons.view');
        $reader->givePermissionTo('exports.create');
        Role::findByName('admin')->revokePermissionTo('audit.view');

        $this->seedAccess();

        $this->assertSame(5, Role::count());
        $reader = Role::findByName('reader');
        $this->assertFalse($reader->hasPermissionTo('persons.view'));
        $this->assertTrue($reader->hasPermissionTo('exports.create'));
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('audit.view'));
    }
}
