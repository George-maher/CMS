<?php

namespace App\Modules\User\Requests;

use App\Enums\UserRole;
use App\Rules\NotPlaceholder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedRoles = array_diff(UserRole::values(), [UserRole::PlatformAdmin->value]);

        return [
            'name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'email' => ['required', 'email', 'unique:users,email', new NotPlaceholder],
            'password' => ['sometimes', 'string', 'min:8', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#^()_\-+=]).+$/'],
            'role' => ['required', Rule::in($allowedRoles)],
            'class_year_id' => ['nullable', 'integer', 'exists:class_years,id'],
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'phone' => ['nullable', 'digits:11', 'regex:/^[0-9]{11}$/', new NotPlaceholder],
            'address' => ['nullable', 'string', 'max:500', new NotPlaceholder],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
