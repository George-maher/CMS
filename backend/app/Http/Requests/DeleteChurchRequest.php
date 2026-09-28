<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class DeleteChurchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::PlatformAdmin;
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'in:DELETE CHURCH'],
            'password' => ['required', 'string', function (string $attribute, string $value, \Closure $fail) {
                /** @var \App\Models\User $user */
                $user = $this->user();
                if (!Hash::check($value, $user->password)) {
                    $fail(__('church_deletion.reauth_password_incorrect'));
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.in' => __('church_deletion.confirmation_required'),
        ];
    }
}
