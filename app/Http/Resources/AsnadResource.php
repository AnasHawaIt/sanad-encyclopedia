<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\InternalFields;
use App\Models\Asnad;
use App\Services\Asnad\ChainCounter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/** @mixin Asnad */
class AsnadResource extends JsonResource
{
    use InternalFields;

    /** @var array<int,array<string,mixed>> نتيجة ChainCounter::authorCheck مفهرسة بمعرّف الكتاب */
    public array $authors = [];

    public function withAuthors(array $authors): static
    {
        $this->authors = collect($authors)->keyBy('book_id')->all();

        return $this;
    }

    public function toArray(Request $request): array
    {
        $internal = $this->isInternal($request);
        $counter = app(ChainCounter::class);
        $counts = $this->relationLoaded('nodes')
            ? $counter->forAsnad($this->resource) + ['definitions' => $counter->definitions()]
            : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'sanad_type_id' => $this->sanad_type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type ? ['id' => $this->type->id, 'name' => $this->type->name] : null),
            // النص الأصلي كما حُفظ، لا يُعدَّل
            'chain_text_original' => $this->chain_text_original,
            'count_from_seq' => $this->count_from_seq,
            'count_to_seq' => $this->count_to_seq,
            'verification_status' => $this->status($this->verification_status),
            'publication_status' => $this->status($this->publication_status),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'nodes' => $this->whenLoaded('nodes', fn () => AsnadNodeResource::collection($this->nodes)),
            'counts' => $counts,
            'books' => $this->whenLoaded('books', fn () => $this->books->map(fn ($b) => $this->filterNested([
                'id' => $b->id,
                'title' => $b->title,
                'book_edition_id' => $b->pivot->book_edition_id,
                'author_in_chain' => $this->authors[$b->id]['author_in_chain'] ?? null,
                'notes' => $this->when($internal, $b->pivot->notes),
            ]))->values()),
            'ijazas' => $this->whenLoaded('ijazas', fn () => $this->ijazas->map(fn ($i) => $this->filterNested([
                'id' => $i->id,
                'ijaza_number' => $i->ijaza_number,
                'notes' => $this->when($internal, $i->pivot->notes),
            ]))->values()),
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
