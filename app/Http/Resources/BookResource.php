<?php

namespace App\Http\Resources;

use App\Enums\ContributorRole;
use App\Http\Resources\Concerns\InternalFields;
use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/** @mixin Book */
class BookResource extends JsonResource
{
    use InternalFields;

    public function toArray(Request $request): array
    {
        $internal = $this->isInternal($request);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'author_person_id' => $this->author_person_id,
            // يُحمَّل المؤلف بقيد الرؤية: مؤلف غير منشور يظهر null لا اسمه
            'author' => $this->whenLoaded('author', fn () => $this->author ? new PersonBriefResource($this->author) : null),
            'subject_category_id' => $this->subject_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id, 'name' => $this->category->name,
            ] : null),
            'summary' => $this->summary,
            'description' => $this->description,
            'verification_status' => $this->status($this->verification_status),
            'publication_status' => $this->status($this->publication_status),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'alternate_titles' => $this->whenLoaded('alternateTitles', fn () => $this->alternateTitles->map(fn ($t) => $this->filterNested([
                'id' => $t->id, 'title' => $t->title, 'notes' => $this->when($internal, $t->notes),
            ]))->values()),
            // مساهم غير مرئي للقارئ لا يُذكر أصلًا
            'contributors' => $this->whenLoaded('contributors', fn () => $this->contributors
                ->filter(fn ($c) => $c->person !== null)
                ->map(fn ($c) => $this->filterNested([
                    'id' => $c->id,
                    'role' => ['value' => $c->role, 'label' => ContributorRole::tryFrom((string) $c->role)?->label()],
                    'person' => new PersonBriefResource($c->person),
                    'notes' => $this->when($internal, $c->notes),
                ]))->values()),
            'editions' => $this->whenLoaded('editions', fn () => EditionResource::collection($this->editions)),
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
