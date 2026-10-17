<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * قاعدة الظهور الوحيدة للسجلات: كل استعلام (قائمة، بحث، عرض، اقتراح، تصدير) يمر من هنا.
 * من لا يملك records.view_unpublished لا يرى إلا ما هو «موثّق» و«منشور» معًا.
 */
trait ScopesVisibility
{
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user !== null && $user->can('records.view_unpublished')) {
            return $query;
        }

        return $query->verified()->published();
    }
}
