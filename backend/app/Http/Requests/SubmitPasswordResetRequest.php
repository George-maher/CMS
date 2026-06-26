<?php

namespace App\Http\Requests;

use App\Rules\NotPlaceholder;
use Illuminate\Foundation\Http\FormRequest;

class SubmitPasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', new NotPlaceholder],
            'notes' => ['nullable', 'string', 'max:1000', new NotPlaceholder],
        ];
    }
}
