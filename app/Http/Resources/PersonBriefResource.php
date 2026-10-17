<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\InternalFields;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * بطاقة العلَم المختصرة للقوائم ولما يُذكر داخل سجل آخر (مؤلف، شيخ، محقق…).
 *
 * @mixin Person
 */
class PersonBriefResource extends JsonResource
{
    use InternalFields;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'primary_name' => $this->primary_name,
            'full_name' => $this->full_name,
            'kunyah' => $this->kunyah,
            'nisbah' => $this->nisbah,
            'birth_year_h' => $this->birth_year_h,
            'death_year_h' => $this->death_year_h,
            'verification_status' => $this->status($this->verification_status),
            'publication_status' => $this->status($this->publication_status),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
