<?php

namespace App\Http\Requests\Persons;

use App\Enums\RelationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'teacher_person_id' => ['required', 'integer', Rule::exists('persons', 'id')->whereNull('deleted_at')->whereNull('merged_into_person_id')],
            'relation_type' => ['sometimes', Rule::enum(RelationType::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
