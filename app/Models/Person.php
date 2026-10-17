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

class Person extends Model
{
    use HasAttachments;
    use HasAuditUsers;
    use HasFactory;
    use HasReviews;
    use HasSourceReferences;
    use HasWorkflowStatus;
    use ScopesVisibility;
    use SoftDeletes;

    // لا يُشتق الاسم من Person (سيصير people)
    protected $table = 'persons';

    protected $fillable = [
        'primary_name',
        'full_name',
        'kunyah',
        'nisbah',
        'name_search',
        'birth_year_h',
        'death_year_h',
        'country_or_region',
        'biography',
        'notes',
        'merged_into_person_id',
    ];

    /** السجلات القائمة بذاتها، أي غير المدموجة في سجل آخر. */
    public function scopeCanonical(Builder $query): Builder
    {
        return $query->whereNull('merged_into_person_id');
    }

    /**
     * بحث بالاسم يتجاهل التشكيل وصور الهمزات والتاء المربوطة. كل كلمة من الاستعلام يجب أن تظهر
     * في الاسم أو في إحدى صيغه البديلة، بأي ترتيب.
     */
    public function scopeSearchName(Builder $query, string $term): Builder
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
                ->where('name_search', 'like', $like)
                ->orWhereHas('nameVariants', fn (Builder $v) => $v->where('name_search', 'like', $like)));
        }

        return $query;
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_person_id');
    }

    public function mergedFrom(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_person_id');
    }

    public function nameVariants(): HasMany
    {
        return $this->hasMany(PersonNameVariant::class);
    }

    public function dateClaims(): HasMany
    {
        return $this->hasMany(PersonDateClaim::class);
    }

    /** الشيوخ: الصفوف التي يكون فيها هذا الشخص تلميذاً. */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'person_relations', 'student_person_id', 'teacher_person_id')
            ->withPivot(['relation_type', 'verification_status', 'notes'])
            ->withTimestamps();
    }

    /** التلاميذ: الصفوف التي يكون فيها هذا الشخص شيخاً. */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'person_relations', 'teacher_person_id', 'student_person_id')
            ->withPivot(['relation_type', 'verification_status', 'notes'])
            ->withTimestamps();
    }

    public function authoredBooks(): HasMany
    {
        return $this->hasMany(Book::class, 'author_person_id');
    }

    public function bookContributions(): HasMany
    {
        return $this->hasMany(BookContributor::class);
    }

    public function grantedIjazas(): HasMany
    {
        return $this->hasMany(Ijaza::class, 'grantor_person_id');
    }

    public function receivedIjazas(): HasMany
    {
        return $this->hasMany(Ijaza::class, 'recipient_person_id');
    }

    public function asnadNodes(): HasMany
    {
        return $this->hasMany(AsnadNode::class);
    }
}
