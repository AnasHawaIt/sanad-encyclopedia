<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait FindsRecords
{
    /**
     * يجلب سجلًا بقيد الرؤية. غير المرئي يعطي 404 لا 403، فلا يُكشف وجود سجل غير منشور.
     *
     * @param  class-string<Model>  $model
     */
    protected function findVisible(string $model, int $id, Request $request, bool $withTrashed = false): Model
    {
        $query = $model::query()->visibleTo($request->user());
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->findOrFail($id);
    }

    /** المؤرشَف يراه من يملك صلاحية الحذف فقط (ليستعيده). */
    protected function seesArchived(Request $request, string $deletePermission): bool
    {
        return (bool) $request->user()?->can($deletePermission);
    }
}
