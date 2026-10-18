<?php

namespace App\Http\Requests\Asnads;

use Illuminate\Foundation\Http\FormRequest;

class MoveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['position' => ['required', 'integer', 'min:1']];
    }
}
