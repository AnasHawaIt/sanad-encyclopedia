<?php

namespace App\Http\Requests\Asnads;

use App\Services\Asnad\AsnadService;
use Illuminate\Foundation\Http\FormRequest;

class ReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order' => ['required', 'array', 'min:1', 'max:'.AsnadService::MAX_NODES],
            'order.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
