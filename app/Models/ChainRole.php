<?php

namespace App\Models;

use App\Models\Concerns\IsLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChainRole extends Model
{
    use IsLookup;

    protected $table = 'chain_roles';

    public function asnadNodes(): HasMany
    {
        return $this->hasMany(AsnadNode::class, 'chain_role_id');
    }
}
