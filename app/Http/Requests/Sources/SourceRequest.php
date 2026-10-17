<?php

namespace App\Http\Requests\Sources;

use App\Enums\SourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $title = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'title' => [...$title, 'string', 'max:500'],
            'author' => ['sometimes', 'nullable', 'string', 'max:500'],
            'source_type' => ['sometimes', Rule::enum(SourceType::class)],
            'publication_details' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'identifier' => ['sometimes', 'nullable', 'string', 'max:255'],
            // http/https فقط: روابط مثل javascript: تُرفض لأنها تُعرض في الواجهة روابطَ قابلة للنقر
            'url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
