<?php

namespace App\Http\Requests\Asnads;

use App\Services\Asnad\AsnadService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * إضافة سند وتعديله. العناصر تُرسل عند الإنشاء فقط؛ بعدها تُعدَّل عبر مسارات العناصر (إدراج، حذف، ترتيب).
 * حالتا التوثيق والنشر لا تُقبلان هنا أبدًا.
 */
class AsnadRequest extends FormRequest
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
            'title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sanad_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sanad_types', 'id')->where('is_active', true)],
            'chain_text_original' => ['sometimes', 'nullable', 'string', 'max:500000'],
            'count_from_seq' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.AsnadService::MAX_NODES],
            'count_to_seq' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.AsnadService::MAX_NODES],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'nodes' => $create ? ['sometimes', 'array', 'max:'.AsnadService::MAX_NODES] : ['prohibited'],
            'nodes.*.person_id' => ['nullable', 'integer', $person],
            'nodes.*.unresolved_name' => ['nullable', 'string', 'max:500', 'required_without:nodes.*.person_id'],
            'nodes.*.text_segment' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'nodes.*.chain_role_id' => ['sometimes', 'nullable', 'integer', Rule::exists('chain_roles', 'id')->where('is_active', true)],
            'nodes.*.transmission_mode_id' => ['sometimes', 'nullable', 'integer', Rule::exists('transmission_modes', 'id')->where('is_active', true)],
            'nodes.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $from = $this->input('count_from_seq');
            $to = $this->input('count_to_seq');
            // كلاهما مُرسَل: نفحصهما معًا هنا. إن أُرسل أحدهما فقط فتفحصه الخدمة مع القيمة المحفوظة.
            if ($from !== null && $to !== null && (int) $to < (int) $from) {
                $validator->errors()->add('count_to_seq', __('asnad.bounds_invalid', ['max' => AsnadService::MAX_NODES]));
            }
        }];
    }
}
