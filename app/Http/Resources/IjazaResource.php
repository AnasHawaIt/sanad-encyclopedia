<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\InternalFields;
use App\Models\Ijaza;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/** @mixin Ijaza */
class IjazaResource extends JsonResource
{
    use InternalFields;

    /** @var list<array{code:string,message:string}> */
    public array $warnings = [];

    public function withWarnings(array $warnings): static
    {
        $this->warnings = $warnings;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $internal = $this->isInternal($request);

        return [
            'id' => $this->id,
            'ijaza_number' => $this->ijaza_number,
            'type_id' => $this->ijaza_type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type ? ['id' => $this->type->id, 'name' => $this->type->name] : null),
            'grantor_person_id' => $this->whenLoaded('grantor', fn () => $this->grantor?->getKey(), null),
            'grantor' => $this->whenLoaded('grantor', fn () => $this->grantor ? new PersonBriefResource($this->grantor) : null),
            'recipient_person_id' => $this->whenLoaded('recipient', fn () => $this->recipient?->getKey(), null),
            'recipient' => $this->whenLoaded('recipient', fn () => $this->recipient ? new PersonBriefResource($this->recipient) : null),
            'ijaza_date_text' => $this->ijaza_date_text,
            'ijaza_year_h' => $this->ijaza_year_h,
            'ijaza_date_gregorian' => $this->ijaza_date_gregorian?->format('Y-m-d'),
            'ijaza_place' => $this->ijaza_place,
            // الأصلي كما حُفظ؛ التصحيحات في revised_text
            'original_text' => $this->original_text,
            'revised_text' => $this->revised_text,
            'summary' => $this->summary,
            'verification_status' => $this->status($this->verification_status),
            'publication_status' => $this->status($this->publication_status),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'books' => $this->whenLoaded('books', fn () => $this->books->map(fn ($b) => $this->filterNested([
                'id' => $b->id,
                'title' => $b->title,
                'book_edition_id' => $b->pivot->book_edition_id,
                'notes' => $this->when($internal, $b->pivot->notes),
            ]))->values()),
            'asnads' => $this->whenLoaded('asnads', fn () => $this->asnads->map(fn ($a) => $this->filterNested([
                'id' => $a->id,
                'title' => $a->title,
                'notes' => $this->when($internal, $a->pivot->notes),
            ]))->values()),
            // تحذيرات علمية لا تمنع الحفظ (مثل: إجازة بكتب دون سند)
            'warnings' => $this->when($internal, $this->warnings),
            'notes' => $this->when($internal, $this->notes),
            'created_by' => $this->when($internal, $this->created_by),
            'updated_by' => $this->when($internal, $this->updated_by),
        ];
    }

    /** @param array<string,mixed> $row يحذف المفاتيح الشرطية الفارغة داخل الصفوف المتداخلة */
    private function filterNested(array $row): array
    {
        return array_filter($row, fn ($v) => ! $v instanceof MissingValue);
    }
}
