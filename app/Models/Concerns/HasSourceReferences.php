<?php

namespace App\Models\Concerns;

use App\Models\SourceReference;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasSourceReferences
{
    public function sourceReferences(): MorphMany
    {
        return $this->morphMany(SourceReference::class, 'referenceable');
    }
}
