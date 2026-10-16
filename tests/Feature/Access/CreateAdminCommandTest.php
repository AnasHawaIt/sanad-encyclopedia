<?php

namespace Tests\Feature\Access;

use App\Models\User;
use Tests\DatabaseTestCase;

class CreateAdminCommandTest extends DatabaseTestCase
{
    public function test_creates_admin_seeding_roles_when_missing(): void
    {
        $this->artisan('sanad:create-admin', ['email' => 'root@example.test', '--name' => 'المدير'])
            ->expectsQuestion('كلمة المرور', 'Str0ngPassw0rd')
            ->expectsQuestion('تأكيد كلمة المرور', 'Str0ngPassw0rd')
            ->assertSuccessful();

        $user = User::where('email', 'root@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->can('users.manage'));
        $this->assertTrue($user->is_active);
    }

    public function test_refuses_mismatched_or_weak_password_and_existing_email(): void
    {
        $this->artisan('sanad:create-admin', ['email' => 'a@example.test', '--name' => 'x'])
            ->expectsQuestion('كلمة المرور', 'Str0ngPassw0rd')
            ->expectsQuestion('تأكيد كلمة المرور', 'different')
            ->assertFailed();

        $this->artisan('sanad:create-admin', ['email' => 'a@example.test', '--name' => 'x'])
            ->expectsQuestion('كلمة المرور', 'weak')
            ->expectsQuestion('تأكيد كلمة المرور', 'weak')
            ->assertFailed();

        $this->assertSame(0, User::where('email', 'a@example.test')->count());

        User::factory()->create(['email' => 'taken@example.test']);
        $this->artisan('sanad:create-admin', ['email' => 'taken@example.test', '--name' => 'x'])->assertFailed();
    }
}
