<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** للجداول التي فيها created_by وupdated_by معاً. */
trait HasAuditUsers
{
    use HasCreator;

    public function initializeHasAuditUsers(): void
    {
        $this->mergeFillable(['updated_by']);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
