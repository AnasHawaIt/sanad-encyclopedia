<?php

namespace App\Http\Resources;

use App\Enums\SourceType;
use App\Http\Resources\Concerns\InternalFields;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Source */
class SourceResource extends JsonResource
{
    use InternalFields;

    public function toArray(Request $request): array
    {
        $type = SourceType::tryFrom((string) $this->source_type);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => $this->author,
            'source_type' => ['value' => $this->source_type, 'label' => $type?->label()],
            'publication_details' => $this->publication_details,
            'identifier' => $this->identifier,
            'url' => $this->url,
            'references_count' => $this->whenCounted('references'),
            'created_at' => $this->created_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'notes' => $this->when($this->isInternal($request), $this->notes),
            'created_by' => $this->when($this->isInternal($request), $this->created_by),
        ];
    }
}
