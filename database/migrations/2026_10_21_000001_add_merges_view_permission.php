<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحية جديدة لعرض طلبات الدمج. الأدوار الموجودة لا تُمنح شيئًا تلقائيًا عند إعادة تشغيل الـ seeder،
 * فنمنحها هنا للأدوار التي تملك أصلًا أي صلاحية دمج، ولا نلمس غيرها.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $view = Permission::findOrCreate('merges.view', 'web');

        Role::query()->where('guard_name', 'web')->get()->each(function (Role $role) use ($view) {
            if ($role->hasAnyPermission(['merges.request', 'merges.approve', 'merges.revert']) && ! $role->hasPermissionTo($view)) {
                $role->givePermissionTo($view);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->where('name', 'merges.view')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
