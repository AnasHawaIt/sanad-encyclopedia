<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookTitle extends Model
{
    protected $fillable = ['book_id', 'title', 'title_search', 'notes'];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
