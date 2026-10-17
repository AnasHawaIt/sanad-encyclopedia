<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\InternalFields;
use App\Models\SourceReference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SourceReference */
class ReferenceResource extends JsonResource
{
    use InternalFields;

    public function toArray(Request $request): array
    {
        $internal = $this->isInternal($request);

        return [
            'id' => $this->id,
            'source_id' => $this->source_id,
            'source' => $this->whenLoaded('source', fn () => $this->source ? new SourceResource($this->source) : null),
            'volume' => $this->volume,
            'page' => $this->page,
            'manuscript_ref' => $this->manuscript_ref,
            'quote_text' => $this->quote_text,
            'reference_status' => $this->status($this->reference_status),
            'created_at' => $this->created_at?->toIso8601String(),
            'note' => $this->when($internal, $this->note),
            'created_by' => $this->when($internal, $this->created_by),
        ];
    }
}
