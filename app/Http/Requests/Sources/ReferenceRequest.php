<?php

namespace App\Http\Requests\Sources;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $source = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'source_id' => [...$source, 'integer', Rule::exists('sources', 'id')->whereNull('deleted_at')],
            'volume' => ['sometimes', 'nullable', 'string', 'max:50'],
            'page' => ['sometimes', 'nullable', 'string', 'max:50'],
            'manuscript_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quote_text' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
