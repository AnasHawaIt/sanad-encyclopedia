<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\InternalFields;
use App\Models\Asnad;
use App\Services\Asnad\ChainCounter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Asnad */
class AsnadBriefResource extends JsonResource
{
    use InternalFields;

    public function toArray(Request $request): array
    {
        $counter = app(ChainCounter::class);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'sanad_type_id' => $this->sanad_type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type ? ['id' => $this->type->id, 'name' => $this->type->name] : null),
            'verification_status' => $this->status($this->verification_status),
            'publication_status' => $this->status($this->publication_status),
            // أرقام مختصرة؛ تعريفاتها في GET lookups/options (count_definitions) وفي تفاصيل السند
            'counts' => $this->relationLoaded('nodes') ? $counter->compact($counter->forAsnad($this->resource)) : null,
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
