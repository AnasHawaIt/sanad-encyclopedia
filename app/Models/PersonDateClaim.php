<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonDateClaim extends Model
{
    protected $fillable = ['person_id', 'claim_type', 'year', 'calendar', 'certainty', 'is_preferred', 'notes'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'is_preferred' => 'boolean'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
