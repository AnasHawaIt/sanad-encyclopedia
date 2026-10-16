<?php

namespace App\Services\Access;

/** قراءة كتالوج الصلاحيات من config/access.php. لا يلمس قاعدة البيانات. */
class PermissionCatalog
{
    /** @return array<string,string> اسم الصلاحية => وصفها العربي */
    public function all(): array
    {
        $result = [];
        foreach (config('access.groups', []) as $group => $definition) {
            foreach ($definition['actions'] as $action => $label) {
                $result["{$group}.{$action}"] = "{$definition['label']}: {$label}";
            }
        }

        return $result;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string,array{label:string,permissions:array<string,string>}> */
    public function groups(): array
    {
        $groups = [];
        foreach (config('access.groups', []) as $group => $definition) {
            $permissions = [];
            foreach ($definition['actions'] as $action => $label) {
                $permissions["{$group}.{$action}"] = $label;
            }
            $groups[$group] = ['label' => $definition['label'], 'permissions' => $permissions];
        }

        return $groups;
    }

    /** @return list<string> صلاحيات الدور الافتراضية بعد توسيع '*' */
    public function defaultPermissionsFor(string $role): array
    {
        $permissions = config("access.roles.{$role}.permissions", []);

        return $permissions === '*' ? $this->names() : array_values($permissions);
    }

    public function adminRole(): string
    {
        return config('access.admin_role', 'admin');
    }

    public function isProtectedRole(string $role): bool
    {
        return in_array($role, config('access.protected_roles', []), true);
    }
}
