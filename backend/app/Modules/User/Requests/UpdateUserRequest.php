<?php

namespace App\Modules\User\Requests;

use App\Enums\UserRole;
use App\Rules\NotPlaceholder;
use App\Rules\PhoneRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedRoles = array_diff(UserRole::values(), [UserRole::PlatformAdmin->value]);

        return [
            'name' => ['sometimes', 'string', 'max:255', new NotPlaceholder],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($this->route('id')), new NotPlaceholder],
            'password' => ['sometimes', 'string', 'min:8', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#^()_\-+=]).+$/'],
            'role' => ['sometimes', Rule::in($allowedRoles)],
            'class_year_id' => ['nullable', 'integer', 'exists:class_years,id'],
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'phone' => ['nullable', new PhoneRule, new NotPlaceholder],
            'address' => ['nullable', 'string', 'max:500', new NotPlaceholder],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
