<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use App\Services\Search\ArabicNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Source extends Model
{
    use HasCreator;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'title', 'title_search', 'author', 'author_search', 'source_type',
        'publication_details', 'identifier', 'url', 'notes',
    ];

    /** بحث في العنوان أو المؤلف يتجاهل التشكيل ويقبل الكلمات بأي ترتيب. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $normalizer = app(ArabicNormalizer::class);
        $tokens = $normalizer->tokens($term);
        if ($tokens === []) {
            // نص لا يحوي حرفًا ولا رقمًا (مثل %) لا يطابق شيئًا؛ لا يُعامل كأنه بلا تصفية
            return $query->whereRaw('1 = 0');
        }

        foreach ($tokens as $token) {
            $like = '%'.$normalizer->escapeLike($token).'%';
            $query->where(fn (Builder $q) => $q->where('title_search', 'like', $like)->orWhere('author_search', 'like', $like));
        }

        return $query;
    }

    public function references(): HasMany
    {
        return $this->hasMany(SourceReference::class);
    }
}
