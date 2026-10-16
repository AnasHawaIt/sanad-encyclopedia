<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Access\PermissionCatalog;
use App\Services\Users\UserService;
use App\Support\PasswordRules;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class CreateAdminUser extends Command
{
    protected $signature = 'sanad:create-admin {email : بريد المدير} {--name= : الاسم}';

    protected $description = 'ينشئ حساب مدير نظام (كلمة المرور تُطلب تفاعليًّا ولا تُمرَّر كوسيط)';

    public function handle(UserService $users, PermissionCatalog $catalog): int
    {
        $email = (string) $this->argument('email');

        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->error('هذا البريد مستخدم من قبل (قد يكون لحساب محذوف).');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('الاسم');
        $password = (string) $this->secret('كلمة المرور');
        $confirm = (string) $this->secret('تأكيد كلمة المرور');

        if ($password !== $confirm) {
            $this->error('كلمتا المرور غير متطابقتين.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email'], 'password' => [PasswordRules::default()]]
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if (! Role::where('name', $catalog->adminRole())->exists()) {
            $this->callSilent('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
        }

        $user = $users->create(null, [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
            'roles' => [$catalog->adminRole()],
        ]);

        $this->info("تم إنشاء المدير: {$user->email}");

        return self::SUCCESS;
    }
}
