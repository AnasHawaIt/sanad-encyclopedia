<?php

namespace App\Models;

use App\Models\Concerns\IsLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IjazaType extends Model
{
    use IsLookup;

    protected $table = 'ijaza_types';

    public function ijazas(): HasMany
    {
        return $this->hasMany(Ijaza::class, 'ijaza_type_id');
    }
}
