<?php

namespace App\Http\Requests\Persons;

use App\Enums\Calendar;
use App\Enums\Certainty;
use App\Enums\ClaimType;
use App\Enums\NameVariantType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * إضافة علَم وتعديله. حالتا التوثيق والنشر لا تُقبلان هنا أبدًا: لهما مسار المراجعة والنشر.
 */
class PersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الصلاحية يفرضها can:persons.* على المسار
    }

    public function rules(): array
    {
        $name = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'primary_name' => [...$name, 'string', 'max:255'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:500'],
            'kunyah' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nisbah' => ['sometimes', 'nullable', 'string', 'max:255'],
            'birth_year_h' => ['sometimes', 'nullable', 'integer', 'between:1,1700'],
            'death_year_h' => ['sometimes', 'nullable', 'integer', 'between:1,1700'],
            'country_or_region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'biography' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'name_variants' => ['sometimes', 'array', 'max:50'],
            'name_variants.*.id' => ['sometimes', 'integer'],
            'name_variants.*.name' => ['required', 'string', 'max:255'],
            'name_variants.*.variant_type' => ['sometimes', Rule::enum(NameVariantType::class)],
            'name_variants.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'date_claims' => ['sometimes', 'array', 'max:20'],
            'date_claims.*.id' => ['sometimes', 'integer'],
            'date_claims.*.claim_type' => ['required', Rule::enum(ClaimType::class)],
            'date_claims.*.year' => ['required', 'integer', 'between:1,2200'],
            'date_claims.*.calendar' => ['sometimes', Rule::enum(Calendar::class)],
            'date_claims.*.certainty' => ['sometimes', Rule::enum(Certainty::class)],
            'date_claims.*.is_preferred' => ['sometimes', 'boolean'],
            'date_claims.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $birth = $this->input('birth_year_h');
            $death = $this->input('death_year_h');
            if ($birth !== null && $death !== null && (int) $death < (int) $birth) {
                $validator->errors()->add('death_year_h', __('records.death_before_birth'));
            }

            $claims = collect($this->input('date_claims', []));
            foreach ($claims as $i => $claim) {
                $calendar = $claim['calendar'] ?? Calendar::Hijri->value;
                if ($calendar === Calendar::Hijri->value && (int) ($claim['year'] ?? 0) > 1700) {
                    $validator->errors()->add("date_claims.{$i}.year", __('validation.between.numeric', [
                        'attribute' => __('validation.attributes.year'), 'min' => 1, 'max' => 1700,
                    ]));
                }
            }
            foreach ([ClaimType::Birth->value, ClaimType::Death->value] as $type) {
                $preferred = $claims->filter(fn ($c) => ($c['claim_type'] ?? null) === $type && ! empty($c['is_preferred']))->count();
                if ($preferred > 1) {
                    $validator->errors()->add('date_claims', __('records.one_preferred'));
                }
            }
        }];
    }
}
