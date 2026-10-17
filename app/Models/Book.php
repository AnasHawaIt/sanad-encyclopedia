<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasAuditUsers;
use App\Models\Concerns\HasReviews;
use App\Models\Concerns\HasSourceReferences;
use App\Models\Concerns\HasWorkflowStatus;
use App\Models\Concerns\ScopesVisibility;
use App\Services\Search\ArabicNormalizer;
use Illuminate\Database\Eloquent\Builder;
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
    use ScopesVisibility;
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

    /** بحث بعنوان الكتاب أو أحد عناوينه الأخرى، يتجاهل التشكيل ويقبل الكلمات بأي ترتيب. */
    public function scopeSearchTitle(Builder $query, string $term): Builder
    {
        $normalizer = app(ArabicNormalizer::class);
        $tokens = $normalizer->tokens($term);
        if ($tokens === []) {
            // نص لا يحوي حرفًا ولا رقمًا (مثل %) لا يطابق شيئًا؛ لا يُعامل كأنه بلا تصفية
            return $query->whereRaw('1 = 0');
        }

        foreach ($tokens as $token) {
            $like = '%'.$normalizer->escapeLike($token).'%';
            $query->where(fn (Builder $q) => $q
                ->where('title_search', 'like', $like)
                ->orWhereHas('alternateTitles', fn (Builder $t) => $t->where('title_search', 'like', $like)));
        }

        return $query;
    }

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
