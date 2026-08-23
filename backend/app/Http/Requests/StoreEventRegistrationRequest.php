<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
