<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Access\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    /** الصلاحيات الموجودة فعلًا في قاعدة البيانات مجمّعة حسب الكتالوج. */
    public function index(PermissionCatalog $catalog): JsonResponse
    {
        $inDb = Permission::query()->pluck('name')->all();
        $groups = [];

        foreach ($catalog->groups() as $key => $group) {
            $items = [];
            foreach ($group['permissions'] as $name => $label) {
                if (in_array($name, $inDb, true)) {
                    $items[] = ['name' => $name, 'label' => $label];
                }
            }
            $groups[] = ['key' => $key, 'label' => $group['label'], 'permissions' => $items];
        }

        $known = $catalog->names();
        $other = array_values(array_map(
            fn ($n) => ['name' => $n, 'label' => $n],
            array_diff($inDb, $known)
        ));
        if ($other) {
            $groups[] = ['key' => 'other', 'label' => 'أخرى', 'permissions' => $other];
        }

        return response()->json(['data' => $groups]);
    }
}
