<?php

namespace App\Models;

use App\Models\Concerns\IsLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SanadType extends Model
{
    use IsLookup;

    protected $table = 'sanad_types';

    public function asnads(): HasMany
    {
        return $this->hasMany(Asnad::class, 'sanad_type_id');
    }
}
