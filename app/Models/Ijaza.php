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
use Illuminate\Database\Eloquent\SoftDeletes;

class Ijaza extends Model
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
        'ijaza_number',
        'ijaza_type_id',
        'grantor_person_id',
        'recipient_person_id',
        'ijaza_date_text',
        'ijaza_year_h',
        'ijaza_date_gregorian',
        'ijaza_place',
        'original_text',
        'revised_text',
        'text_search',
        'summary',
        'notes',
    ];

    protected function casts(): array
    {
        return ['ijaza_date_gregorian' => 'date'];
    }

    /**
     * بحث في نص الإجازة (الأصلي والمصحَّح معًا) بعد التطبيع. كل كلمة يجب أن ترد في النص بأي ترتيب.
     * نستعمل LIKE لا FULLTEXT عمدًا: FULLTEXT في InnoDB يتجاهل الكلمات الأقصر من ثلاثة أحرف
     * ولا يرى الصفوف قبل اعتماد المعاملة، وكلاهما يُسقط نتائج صحيحة في الأسماء العربية القصيرة.
     */
    public function scopeSearchText(Builder $query, string $term): Builder
    {
        $normalizer = app(ArabicNormalizer::class);
        $tokens = $normalizer->tokens($term);
        if ($tokens === []) {
            return $query->whereRaw('1 = 0');
        }

        foreach ($tokens as $token) {
            $query->where('text_search', 'like', '%'.$normalizer->escapeLike($token).'%');
        }

        return $query;
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(IjazaType::class, 'ijaza_type_id');
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'grantor_person_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'recipient_person_id');
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'ijaza_book')
            ->withPivot(['book_edition_id', 'notes'])
            ->withTimestamps();
    }

    public function asnads(): BelongsToMany
    {
        return $this->belongsToMany(Asnad::class, 'ijaza_asnad')
            ->withPivot(['notes'])
            ->withTimestamps();
    }
}
