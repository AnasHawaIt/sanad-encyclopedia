<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\Access\PermissionCatalog;
use App\Services\Audit\ActivityLogger;
use App\Services\Auth\SessionRevoker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(
        private ActivityLogger $log,
        private SessionRevoker $revoker,
        private PermissionCatalog $catalog,
    ) {}

    public function create(?User $actor, array $data): User
    {
        $roles = $data['roles'] ?? [];
        $this->assertMayAssignRoles($actor, [], $roles);

        return DB::transaction(function () use ($actor, $data, $roles) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);
            $user->syncRoles($roles);

            $this->log->record('users.created', $user, [
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => $roles,
            ], $actor);

            return $user->load('roles');
        });
    }

    public function update(User $actor, User $user, array $data): User
    {
        $this->assertMayTouch($actor, $user);

        return DB::transaction(function () use ($actor, $user, $data) {
            $changes = [];

            if (array_key_exists('is_active', $data) && (bool) $data['is_active'] !== $user->is_active && ! $data['is_active']) {
                $this->assertNotSelf($actor, $user);
                $this->assertNotLastAdmin($user);
            }

            if (array_key_exists('roles', $data)) {
                $old = $user->getRoleNames()->all();
                $new = array_values($data['roles']);
                $this->assertMayAssignRoles($actor, $old, $new);
                $admin = $this->catalog->adminRole();
                if (in_array($admin, $old, true) && ! in_array($admin, $new, true)) {
                    $this->assertNotLastAdmin($user);
                }
                $a = $old;
                $b = $new;
                sort($a);
                sort($b);
                if ($a !== $b) {
                    $changes['roles'] = ['from' => $old, 'to' => $new];
                }
                $user->syncRoles($new);
            }

            foreach (['name', 'email'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== $user->{$field}) {
                    $changes[$field] = ['from' => $user->{$field}, 'to' => $data[$field]];
                    $user->{$field} = $data[$field];
                }
            }

            $deactivated = false;
            if (array_key_exists('is_active', $data) && (bool) $data['is_active'] !== $user->is_active) {
                $changes['is_active'] = ['from' => $user->is_active, 'to' => (bool) $data['is_active']];
                $deactivated = ! $data['is_active'];
                $user->is_active = (bool) $data['is_active'];
            }

            $passwordChanged = ! empty($data['password']);
            if ($passwordChanged) {
                $user->password = $data['password'];
                $changes['credentials_changed'] = true; // لا نسمّيه password كي لا يحجبه المسجِّل
            }

            $user->save();

            if ($deactivated || $passwordChanged) {
                $keep = ($passwordChanged && ! $deactivated && $actor->is($user) && request()?->hasSession())
                    ? request()->session()->getId() : null;
                $this->revoker->revoke($user, $keep);
            }

            if ($changes !== []) {
                $this->log->record('users.updated', $user, $changes, $actor);
            }

            return $user->load('roles');
        });
    }

    public function delete(User $actor, User $user): void
    {
        $this->assertMayTouch($actor, $user);
        $this->assertNotSelf($actor, $user);
        $this->assertNotLastAdmin($user);

        DB::transaction(function () use ($actor, $user) {
            $this->revoker->revoke($user);
            $user->delete();
            $this->log->record('users.deleted', $user, ['email' => $user->email], $actor);
        });
    }

    public function restore(User $actor, User $user): User
    {
        $this->assertMayTouch($actor, $user);
        $user->restore();
        $this->log->record('users.restored', $user, [], $actor);

        return $user->load('roles');
    }

    private function assertNotSelf(User $actor, User $user): void
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['user' => [__('access.cannot_target_self')]]);
        }
    }

    /** لا يجوز أن يخلو النظام من مدير نشط. */
    public function assertNotLastAdmin(User $user): void
    {
        $admin = $this->catalog->adminRole();
        if ($user->trashed() || ! $user->is_active || ! $user->hasRole($admin)) {
            return;
        }

        $others = User::role($admin)->where('is_active', true)->whereKeyNot($user->getKey())->count();
        if ($others === 0) {
            throw ValidationException::withMessages(['user' => [__('access.last_admin')]]);
        }
    }

    /** غير المدير لا يعدّل حساب مدير. */
    private function assertMayTouch(User $actor, User $target): void
    {
        $admin = $this->catalog->adminRole();
        if ($target->hasRole($admin) && ! $actor->hasRole($admin)) {
            abort(403, __('access.admin_only'));
        }
    }

    /** منح دور المدير أو سحبه حكر على المدير. */
    private function assertMayAssignRoles(?User $actor, array $old, array $new): void
    {
        $admin = $this->catalog->adminRole();
        $touchesAdmin = in_array($admin, $old, true) !== in_array($admin, $new, true);
        if ($touchesAdmin && $actor && ! $actor->hasRole($admin)) {
            abort(403, __('access.admin_only'));
        }
    }
}
