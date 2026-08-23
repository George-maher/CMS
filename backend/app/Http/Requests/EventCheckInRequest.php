<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string', 'min:20', 'max:64'],
        ];
    }
}
