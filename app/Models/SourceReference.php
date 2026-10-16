<?php

namespace App\Models;

use App\Enums\ReferenceStatus;
use App\Models\Concerns\HasCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SourceReference extends Model
{
    use HasCreator;

    protected $fillable = [
        'source_id',
        'referenceable_type',
        'referenceable_id',
        'volume',
        'page',
        'manuscript_ref',
        'quote_text',
        'note',
        'reference_status',
    ];

    protected $attributes = ['reference_status' => 'unverified'];

    protected function casts(): array
    {
        return ['reference_status' => ReferenceStatus::class];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function referenceable(): MorphTo
    {
        return $this->morphTo();
    }
}
