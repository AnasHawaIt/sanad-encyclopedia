<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Source extends Model
{
    use HasCreator;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['title', 'author', 'source_type', 'publication_details', 'identifier', 'url', 'notes'];

    public function references(): HasMany
    {
        return $this->hasMany(SourceReference::class);
    }
}
