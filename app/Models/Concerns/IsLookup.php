<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * جداول التصنيفات القابلة للإدارة من لوحة التحكم (تُعطَّل ولا تُحذف).
 */
trait IsLookup
{
    public function initializeIsLookup(): void
    {
        $this->mergeFillable(['name', 'description', 'is_active', 'sort_order']);
        $this->mergeCasts(['is_active' => 'boolean', 'sort_order' => 'integer']);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
