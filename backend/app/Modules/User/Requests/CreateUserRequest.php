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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in($allowedRoles)],
            // Legacy duplicate of class_id. Rejected rather than accepted:
            // `users.class_year_id` is still foreign-keyed to the deprecated
            // `class_years` table while every other `class_year_id` in the
            // schema was repointed at `classes`. Accepting it allowed a client
            // to point a user at a class_years row in any church (the rule was
            // a bare `exists:`, with no ownership check), and it then leaked
            // into EventPolicy's authorization comparison. See EventPolicy.
            'class_year_id' => ['prohibited'],
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'stage_id' => ['nullable', 'integer', 'exists:stages,id'],
            'phone' => ['nullable', new PhoneRule, new NotPlaceholder],
            'address' => ['nullable', 'string', 'max:500', new NotPlaceholder],
            'birthday' => ['nullable', 'date', 'before:today'],
            'member_id' => ['nullable', 'string', 'max:20', 'unique:users,member_id'],
            'member_address' => ['nullable', 'string', 'max:500', new NotPlaceholder],
            'is_active' => ['sometimes', 'boolean'],
        ];
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
}
