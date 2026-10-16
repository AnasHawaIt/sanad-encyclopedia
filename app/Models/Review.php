<?php

namespace App\Models;

use App\Enums\ReviewAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** سجل مراجعة للإلحاق فقط. */
class Review extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['reviewable_type', 'reviewable_id', 'reviewer_id', 'action', 'from_status', 'to_status', 'notes'];

    protected function casts(): array
    {
        return ['action' => ReviewAction::class];
    }

    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
