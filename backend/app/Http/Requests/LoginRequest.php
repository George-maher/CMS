<?php

namespace App\Http\Requests;

use App\Rules\NotPlaceholder;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', new NotPlaceholder],
            'password' => ['required', 'string'],
        ];
    }

}
