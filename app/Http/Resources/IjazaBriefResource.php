<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\InternalFields;
use App\Models\Ijaza;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ijaza */
class IjazaBriefResource extends JsonResource
{
    use InternalFields;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ijaza_number' => $this->ijaza_number,
            'type' => $this->whenLoaded('type', fn () => $this->type ? ['id' => $this->type->id, 'name' => $this->type->name] : null),
            'grantor' => $this->whenLoaded('grantor', fn () => $this->grantor ? new PersonBriefResource($this->grantor) : null),
            'recipient' => $this->whenLoaded('recipient', fn () => $this->recipient ? new PersonBriefResource($this->recipient) : null),
            'ijaza_date_text' => $this->ijaza_date_text,
            'ijaza_year_h' => $this->ijaza_year_h,
            'summary' => $this->summary,
            'verification_status' => $this->status($this->verification_status),
            'publication_status' => $this->status($this->publication_status),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
