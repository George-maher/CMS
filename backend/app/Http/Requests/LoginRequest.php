<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // No NotPlaceholder rule here: placeholder checks belong to
        // registration. Blocking them at login would permanently lock out any
        // real account whose email local part happens to look like a
        // placeholder (e.g. user@..., name@...) — credential verification is
        // what actually authenticates.
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
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
