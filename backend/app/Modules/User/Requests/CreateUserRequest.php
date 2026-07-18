<?php

namespace App\Modules\User\Requests;

use App\Enums\UserRole;
use App\Rules\NotPlaceholder;
use App\Rules\PhoneRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var array<int, string> $allowedRoles */
        $allowedRoles = array_diff(UserRole::values(), [UserRole::PlatformAdmin->value]);

        return [
            'name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'email' => ['required', 'email', 'unique:users,email', new NotPlaceholder],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#^()_\-+=]).+$/'],
            'role' => ['required', Rule::in($allowedRoles)],
            'class_year_id' => ['nullable', 'integer', 'exists:class_years,id'],
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'phone' => ['nullable', new PhoneRule, new NotPlaceholder],
            'address' => ['nullable', 'string', 'max:500', new NotPlaceholder],
            'birthday' => ['nullable', 'date', 'before:today'],
            'member_id' => ['nullable', 'string', 'max:20', 'unique:users,member_id'],
            'member_address' => ['nullable', 'string', 'max:500', new NotPlaceholder],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
