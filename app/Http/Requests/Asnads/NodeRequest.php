<?php

namespace App\Http\Requests\Asnads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $person = Rule::exists('persons', 'id')->whereNull('deleted_at')->whereNull('merged_into_person_id');

        return [
            'person_id' => ['sometimes', 'nullable', 'integer', $person],
            // عند الإضافة يلزم علَم أو اسم؛ عند التعديل تفحص الخدمة النتيجة النهائية
            'unresolved_name' => array_merge($create ? ['required_without:person_id'] : ['sometimes'], ['nullable', 'string', 'max:500']),
            'text_segment' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'chain_role_id' => ['sometimes', 'nullable', 'integer', Rule::exists('chain_roles', 'id')->where('is_active', true)],
            'transmission_mode_id' => ['sometimes', 'nullable', 'integer', Rule::exists('transmission_modes', 'id')->where('is_active', true)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'position' => $create ? ['sometimes', 'integer', 'min:1'] : ['prohibited'],
        ];
    }
}
