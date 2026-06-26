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

    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'in:DELETE CHURCH'],
            'password' => ['required', 'string', function ($attribute, $value, $fail) {
                if (!Hash::check($value, $this->user()->password)) {
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
