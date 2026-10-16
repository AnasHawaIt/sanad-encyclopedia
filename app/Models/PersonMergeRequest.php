<?php

namespace App\Models;

use App\Enums\MergeRequestStatus;
use App\Models\Concerns\HasReviews;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonMergeRequest extends Model
{
    use HasReviews;

    protected $fillable = [
        'primary_person_id',
        'secondary_person_id',
        'status',
        'reason',
        'requested_by',
        'decided_by',
        'decided_at',
        'decision_notes',
        'executed_at',
        'reverted_at',
        'snapshot',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => MergeRequestStatus::class,
            'snapshot' => 'array',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
            'reverted_at' => 'datetime',
        ];
    }

    public function primaryPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'primary_person_id');
    }

    public function secondaryPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'secondary_person_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function repoints(): HasMany
    {
        return $this->hasMany(PersonMergeRepoint::class, 'merge_request_id');
    }
}
