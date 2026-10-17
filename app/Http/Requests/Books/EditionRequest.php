<?php

namespace App\Http\Requests\Books;

use App\Enums\EditionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'edition_type' => ['sometimes', Rule::enum(EditionType::class)],
            'publisher' => ['sometimes', 'nullable', 'string', 'max:255'],
            'editor_person_id' => ['sometimes', 'nullable', 'integer', Rule::exists('persons', 'id')->whereNull('deleted_at')->whereNull('merged_into_person_id')],
            'editor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'publication_year' => ['sometimes', 'nullable', 'integer', 'between:1,2200'],
            'volumes_count' => ['sometimes', 'nullable', 'integer', 'between:1,9999'],
            'manuscript_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'library_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
