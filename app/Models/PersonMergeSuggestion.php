<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** اقتراح تشابه فقط؛ لا يغيّر أي سجل. person_a_id يجب أن يكون أصغر من person_b_id. */
class PersonMergeSuggestion extends Model
{
    protected $fillable = ['person_a_id', 'person_b_id', 'score', 'reasons', 'status', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'score' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }

    public function personA(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_a_id');
    }

    public function personB(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_b_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
