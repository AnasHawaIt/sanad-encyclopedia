<?php

namespace App\Http\Requests\Users;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الصلاحية يفرضها can:users.manage على المسار
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // unique يشمل الحسابات المحذوفة (المحذوف يُستعاد ولا يُعاد إنشاؤه)
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', PasswordRules::default()],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ];
    }
}
