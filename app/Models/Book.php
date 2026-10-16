<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasAuditUsers;
use App\Models\Concerns\HasReviews;
use App\Models\Concerns\HasSourceReferences;
use App\Models\Concerns\HasWorkflowStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Book extends Model
{
    use HasAttachments;
    use HasAuditUsers;
    use HasFactory;
    use HasReviews;
    use HasSourceReferences;
    use HasWorkflowStatus;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'title_search',
        'author_person_id',
        'subject_category_id',
        'summary',
        'description',
        'notes',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'author_person_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SubjectCategory::class, 'subject_category_id');
    }

    public function alternateTitles(): HasMany
    {
        return $this->hasMany(BookTitle::class);
    }

    public function contributors(): HasMany
    {
        return $this->hasMany(BookContributor::class);
    }

    public function editions(): HasMany
    {
        return $this->hasMany(BookEdition::class);
    }

    public function asnads(): BelongsToMany
    {
        return $this->belongsToMany(Asnad::class, 'book_asnad')
            ->withPivot(['book_edition_id', 'notes'])
            ->withTimestamps();
    }

    public function ijazas(): BelongsToMany
    {
        return $this->belongsToMany(Ijaza::class, 'ijaza_book')
            ->withPivot(['book_edition_id', 'notes'])
            ->withTimestamps();
    }
}
