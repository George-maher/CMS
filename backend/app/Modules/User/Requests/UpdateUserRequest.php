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

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $email = $this->input('email');
            if (is_string($email)) {
                $this->merge([
                    'email' => strtolower(trim($email)),
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var array<int, string> $allowedRoles */
        $allowedRoles = array_diff(UserRole::values(), [UserRole::PlatformAdmin->value]);

        return [
            'name' => ['sometimes', 'string', 'max:255', new NotPlaceholder],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($this->route('id')), new NotPlaceholder],
            'password' => ['sometimes', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#^()_\-+=]).+$/'],
            'role' => ['sometimes', Rule::in($allowedRoles)],
            'class_year_id' => ['nullable', 'integer', 'exists:class_years,id'],
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'phone' => ['nullable', new PhoneRule, new NotPlaceholder],
            'address' => ['nullable', 'string', 'max:500', new NotPlaceholder],
            'birthday' => ['nullable', 'date', 'before:today'],
            'member_id' => ['nullable', 'string', 'max:20', Rule::unique('users', 'member_id')->ignore($this->route('id'))],
            'member_address' => ['nullable', 'string', 'max:500', new NotPlaceholder],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
