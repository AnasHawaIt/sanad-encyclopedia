<?php

namespace App\Http\Requests\Books;

use App\Enums\ContributorRole;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $title = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];
        $person = Rule::exists('persons', 'id')->whereNull('deleted_at')->whereNull('merged_into_person_id');

        return [
            'title' => [...$title, 'string', 'max:500'],
            // المؤلف اختياري: لا يُنسب كتاب إلى مؤلف دون توثيق
            'author_person_id' => ['sometimes', 'nullable', 'integer', $person],
            'subject_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('subject_categories', 'id')->where('is_active', true)],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'alternate_titles' => ['sometimes', 'array', 'max:50'],
            'alternate_titles.*.id' => ['sometimes', 'integer'],
            'alternate_titles.*.title' => ['required', 'string', 'max:500'],
            'alternate_titles.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'contributors' => ['sometimes', 'array', 'max:50'],
            'contributors.*.id' => ['sometimes', 'integer'],
            'contributors.*.person_id' => ['required', 'integer', $person],
            'contributors.*.role' => ['sometimes', Rule::enum(ContributorRole::class)],
            'contributors.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $seen = [];
            foreach ($this->input('contributors', []) as $i => $row) {
                $key = ($row['person_id'] ?? '').'|'.($row['role'] ?? ContributorRole::Contributor->value);
                if (isset($seen[$key])) {
                    $validator->errors()->add("contributors.{$i}.person_id", __('records.duplicate_contributor'));
                }
                $seen[$key] = true;
            }
        }];
    }
}
