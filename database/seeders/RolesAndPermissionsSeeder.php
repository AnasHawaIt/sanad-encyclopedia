<?php

namespace Database\Seeders;

use App\Services\Access\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * آمن لإعادة التشغيل: ينشئ الناقص فقط. المدير يُزامَن مع كل الصلاحيات دائمًا،
 * وبقية الأدوار تأخذ الافتراضي عند إنشائها فقط فلا يُمحى تخصيص المدير.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $catalog = app(PermissionCatalog::class);

        foreach ($catalog->names() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (config('access.roles', []) as $name => $definition) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $created = $role->wasRecentlyCreated;

            if ($role->label === null) {
                $role->forceFill(['label' => $definition['label']])->save();
            }

            if ($name === $catalog->adminRole()) {
                $role->syncPermissions($catalog->names());
            } elseif ($created) {
                $role->syncPermissions($catalog->defaultPermissionsFor($name));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
