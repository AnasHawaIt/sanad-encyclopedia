<?php

namespace Tests\Concerns;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/** أدوات مشتركة لاختبارات المصادقة والصلاحيات. */
trait CreatesAccessFixtures
{
    protected function seedAccess(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->setLocale('ar');
    }

    /** يجعل الطلبات تبدو قادمة من الواجهة (شرط Sanctum للجلسات). */
    protected function spa(): static
    {
        config(['sanctum.stateful' => ['localhost']]);

        return $this->withHeaders(['Referer' => 'http://localhost', 'Origin' => 'http://localhost']);
    }

    protected function userWithRole(string $role, array $attributes = []): User
    {
        // نحدد is_active صراحةً: المصنع لا يضعه، فيبقى null على النسخة غير المُعاد تحميلها
        // (الافتراضي true موجود في قاعدة البيانات فقط) وتعاملها EnsureUserIsActive كحساب معطّل.
        $user = User::factory()->create(array_merge(['is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    protected function admin(array $attributes = []): User
    {
        return $this->userWithRole('admin', $attributes);
    }
}
