<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasSourceReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookEdition extends Model
{
    use HasAttachments;
    use HasCreator;
    use HasSourceReferences;
    use SoftDeletes;

    protected $fillable = [
        'book_id',
        'edition_type',
        'publisher',
        'editor_person_id',
        'editor_name',
        'publication_year',
        'volumes_count',
        'manuscript_ref',
        'library_name',
        'notes',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'editor_person_id');
    }
}
