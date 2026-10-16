<?php

namespace App\Services\Access;

use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class RoleService
{
    public function __construct(private ActivityLogger $log, private PermissionCatalog $catalog) {}

    public function create(User $actor, array $data): Role
    {
        $permissions = array_values($data['permissions'] ?? []);
        $this->assertMayGrant($actor, $permissions);

        return DB::transaction(function () use ($actor, $data, $permissions) {
            $role = Role::create(['name' => $data['name'], 'label' => $data['label'], 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
            $this->log->record('roles.created', $role, ['label' => $role->label, 'permissions' => $permissions], $actor);

            return $role->load('permissions');
        });
    }

    public function update(User $actor, Role $role, array $data): Role
    {
        $isAdmin = $role->name === $this->catalog->adminRole();
        if ($isAdmin && array_key_exists('permissions', $data)) {
            throw ValidationException::withMessages(['permissions' => [__('access.admin_role_locked')]]);
        }
        if ($this->catalog->isProtectedRole($role->name) && isset($data['name']) && $data['name'] !== $role->name) {
            throw ValidationException::withMessages(['name' => [__('access.role_protected')]]);
        }

        return DB::transaction(function () use ($actor, $role, $data) {
            $changes = [];
            if (isset($data['name']) && $data['name'] !== $role->name) {
                $changes['name'] = ['from' => $role->name, 'to' => $data['name']];
                $role->name = $data['name'];
            }
            if (isset($data['label']) && $data['label'] !== $role->label) {
                $changes['label'] = ['from' => $role->label, 'to' => $data['label']];
                $role->label = $data['label'];
            }
            $role->save();

            if (array_key_exists('permissions', $data)) {
                $new = array_values($data['permissions']);
                $this->assertMayGrant($actor, $new);
                $old = $role->permissions()->pluck('name')->all();
                $added = array_values(array_diff($new, $old));
                $removed = array_values(array_diff($old, $new));
                if ($added || $removed) {
                    $changes['permissions'] = ['added' => $added, 'removed' => $removed];
                }
                $role->syncPermissions($new);
            }

            if ($changes !== []) {
                $this->log->record('roles.updated', $role, $changes, $actor);
            }

            return $role->load('permissions');
        });
    }

    public function delete(User $actor, Role $role): void
    {
        if ($this->catalog->isProtectedRole($role->name)) {
            throw ValidationException::withMessages(['role' => [__('access.role_protected')]]);
        }
        if ($this->hasAssignedUsers($role)) {
            throw ValidationException::withMessages(['role' => [__('access.role_in_use')]]);
        }

        DB::transaction(function () use ($actor, $role) {
            $this->log->record('roles.deleted', $role, ['name' => $role->name], $actor);
            $role->delete();
        });
    }

    /**
     * يضيف users_count دون علاقة Role::users() لأنها تفشل أثناء طلب auth:sanctum:
     * الحارس الافتراضي يصبح «sanctum» وليس له مزوّد نماذج، فتعيد Spatie نموذجًا null.
     */
    public function withUserCount(Builder $query): Builder
    {
        $pivot = config('permission.table_names.model_has_roles', 'model_has_roles');
        $roles = config('permission.table_names.roles', 'roles');
        $morphKey = config('permission.column_names.model_morph_key', 'model_id');

        $count = DB::table($pivot)
            ->join('users', 'users.id', '=', "{$pivot}.{$morphKey}")
            ->whereColumn("{$pivot}.role_id", "{$roles}.id")
            ->where("{$pivot}.model_type", (new User)->getMorphClass())
            ->whereNull('users.deleted_at')
            ->selectRaw('count(*)');

        return $query->select("{$roles}.*")->addSelect(['users_count' => $count]);
    }

    /** يشمل المستخدمين المحذوفين ناعمًا: استعادتهم لاحقًا يجب ألا تفقدهم دورهم. */
    private function hasAssignedUsers(Role $role): bool
    {
        return DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
            ->where('role_id', $role->getKey())
            ->where('model_type', (new User)->getMorphClass())
            ->exists();
    }

    /** غير المدير لا يمنح صلاحية لا يملكها. */
    private function assertMayGrant(User $actor, array $permissions): void
    {
        if ($actor->hasRole($this->catalog->adminRole())) {
            return;
        }
        $held = $actor->getAllPermissions()->pluck('name')->all();
        if (array_diff($permissions, $held) !== []) {
            abort(403, __('access.cannot_grant'));
        }
    }
}
