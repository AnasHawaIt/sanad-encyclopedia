<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\InternalFields;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Person */
class PersonResource extends JsonResource
{
    use InternalFields;

    public function toArray(Request $request): array
    {
        $internal = $this->isInternal($request);

        return [
            'id' => $this->id,
            'primary_name' => $this->primary_name,
            'full_name' => $this->full_name,
            'kunyah' => $this->kunyah,
            'nisbah' => $this->nisbah,
            'birth_year_h' => $this->birth_year_h,
            'death_year_h' => $this->death_year_h,
            'country_or_region' => $this->country_or_region,
            'biography' => $this->biography,
            'verification_status' => $this->status($this->verification_status),
            'publication_status' => $this->status($this->publication_status),
            // يحوّل العميل صفحة العلَم المدموج القديمة إلى الأساسي
            'merged_into_person_id' => $this->merged_into_person_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'name_variants' => $this->whenLoaded('nameVariants', fn () => $this->nameVariants->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'variant_type' => $v->variant_type,
                'notes' => $this->when($internal, $v->notes),
            ])->values()),
            'date_claims' => $this->whenLoaded('dateClaims', fn () => $this->dateClaims->map(fn ($c) => [
                'id' => $c->id,
                'claim_type' => $c->claim_type,
                'year' => $c->year,
                'calendar' => $c->calendar,
                'certainty' => $c->certainty,
                'is_preferred' => $c->is_preferred,
                'notes' => $this->when($internal, $c->notes),
            ])->values()),
            'notes' => $this->when($internal, $this->notes),
            'created_by' => $this->when($internal, $this->created_by),
            'updated_by' => $this->when($internal, $this->updated_by),
        ];
    }
}
