<?php

namespace App\Models;

use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasSourceReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsnadNode extends Model
{
    use HasCreator;
    use HasFactory;
    use HasSourceReferences;

    protected $fillable = [
        'asnad_id',
        'sequence_number',
        'person_id',
        'unresolved_name',
        'text_segment',
        'chain_role_id',
        'transmission_mode_id',
        'notes',
    ];

    protected function casts(): array
    {
        return ['sequence_number' => 'integer'];
    }

    public function asnad(): BelongsTo
    {
        return $this->belongsTo(Asnad::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(ChainRole::class, 'chain_role_id');
    }

    public function transmissionMode(): BelongsTo
    {
        return $this->belongsTo(TransmissionMode::class, 'transmission_mode_id');
    }
}
