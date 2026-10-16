<?php

namespace App\Models;

use App\Models\Concerns\IsLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransmissionMode extends Model
{
    use IsLookup;

    protected $table = 'transmission_modes';

    public function asnadNodes(): HasMany
    {
        return $this->hasMany(AsnadNode::class, 'transmission_mode_id');
    }
}
