<?php

namespace App\Http\Requests\Asnads;

use Illuminate\Foundation\Http\FormRequest;

class SplitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['text' => ['required', 'string', 'max:50000']];
    }
}
