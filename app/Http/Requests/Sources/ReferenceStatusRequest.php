<?php

namespace App\Http\Requests\Sources;

use App\Enums\ReferenceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReferenceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الصلاحية يفرضها can:reviews.decide على المسار
    }

    public function rules(): array
    {
        return ['reference_status' => ['required', Rule::enum(ReferenceStatus::class)]];
    }
}
