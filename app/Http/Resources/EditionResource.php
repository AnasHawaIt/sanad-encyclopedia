<?php

namespace App\Http\Resources;

use App\Enums\EditionType;
use App\Http\Resources\Concerns\InternalFields;
use App\Models\BookEdition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BookEdition */
class EditionResource extends JsonResource
{
    use InternalFields;

    public function toArray(Request $request): array
    {
        $type = EditionType::tryFrom((string) $this->edition_type);

        return [
            'id' => $this->id,
            'book_id' => $this->book_id,
            'edition_type' => ['value' => $this->edition_type, 'label' => $type?->label()],
            'publisher' => $this->publisher,
            'editor_person_id' => $this->editor_person_id,
            'editor_name' => $this->editor_name,
            'editor' => $this->whenLoaded('editor', fn () => $this->editor ? new PersonBriefResource($this->editor) : null),
            'publication_year' => $this->publication_year,
            'volumes_count' => $this->volumes_count,
            'manuscript_ref' => $this->manuscript_ref,
            'library_name' => $this->library_name,
            'notes' => $this->when($this->isInternal($request), $this->notes),
        ];
    }
}
