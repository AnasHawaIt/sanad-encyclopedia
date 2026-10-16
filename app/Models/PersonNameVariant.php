<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonNameVariant extends Model
{
    protected $fillable = ['person_id', 'name', 'name_search', 'variant_type', 'notes'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
