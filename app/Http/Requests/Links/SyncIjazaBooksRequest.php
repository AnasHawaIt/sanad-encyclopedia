<?php

namespace App\Http\Requests\Links;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncIjazaBooksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:500'],
            'items.*.book_id' => ['required', 'integer', Rule::exists('books', 'id')->whereNull('deleted_at')],
            'items.*.book_edition_id' => ['sometimes', 'nullable', 'integer', 'exists:book_editions,id'],
            'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
