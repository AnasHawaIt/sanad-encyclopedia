<?php

namespace App\Http\Requests\Ijazas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IjazaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $person = Rule::exists('persons', 'id')->whereNull('deleted_at')->whereNull('merged_into_person_id');

        return [
            'ijaza_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'ijaza_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('ijaza_types', 'id')->where('is_active', true)],
            'grantor_person_id' => ['sometimes', 'nullable', 'integer', $person],
            'recipient_person_id' => ['sometimes', 'nullable', 'integer', $person],
            // التاريخ كما كُتب في الوثيقة، مع سنة هجرية وميلادية اختياريتين لا يُحوَّل أحدهما إلى الآخر
            'ijaza_date_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ijaza_year_h' => ['sometimes', 'nullable', 'integer', 'between:1,1700'],
            'ijaza_date_gregorian' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ijaza_place' => ['sometimes', 'nullable', 'string', 'max:255'],
            'original_text' => ['sometimes', 'nullable', 'string', 'max:500000'],
            'revised_text' => ['sometimes', 'nullable', 'string', 'max:500000'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
