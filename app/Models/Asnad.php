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

class Asnad extends Model
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
        'sanad_type_id',
        'chain_text_original',
        'chain_text_search',
        'count_from_seq',
        'count_to_seq',
        'notes',
    ];

    protected function casts(): array
    {
        return ['count_from_seq' => 'integer', 'count_to_seq' => 'integer'];
    }

    /** بحث في عنوان السند ونصه الأصلي المطبَّع: يتجاهل التشكيل ويقبل الكلمات بأي ترتيب. */
    public function scopeSearchText(Builder $query, string $term): Builder
    {
        $normalizer = app(ArabicNormalizer::class);
        $tokens = $normalizer->tokens($term);
        if ($tokens === []) {
            return $query->whereRaw('1 = 0');
        }

        foreach ($tokens as $token) {
            $like = '%'.$normalizer->escapeLike($token).'%';
            $query->where(fn (Builder $q) => $q->where('title_search', 'like', $like)->orWhere('chain_text_search', 'like', $like));
        }

        return $query;
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(SanadType::class, 'sanad_type_id');
    }

    /** العناصر مرتبة دائماً: 1 هو الطرف الأقرب إلينا. */
    public function nodes(): HasMany
    {
        return $this->hasMany(AsnadNode::class)->orderBy('sequence_number');
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_asnad')
            ->withPivot(['book_edition_id', 'notes'])
            ->withTimestamps();
    }

    public function ijazas(): BelongsToMany
    {
        return $this->belongsToMany(Ijaza::class, 'ijaza_asnad')
            ->withPivot(['notes'])
            ->withTimestamps();
    }
}
