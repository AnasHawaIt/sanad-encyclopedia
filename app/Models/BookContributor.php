<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookContributor extends Model
{
    protected $fillable = ['book_id', 'person_id', 'role', 'notes'];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
