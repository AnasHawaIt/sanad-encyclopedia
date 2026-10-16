<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** للجداول التي فيها created_by فقط. ملء القيمة تلقائياً من المستخدم الحالي يأتي في مرحلة المصادقة. */
trait HasCreator
{
    public function initializeHasCreator(): void
    {
        $this->mergeFillable(['created_by']);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
