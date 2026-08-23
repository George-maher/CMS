<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignBusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bus_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bus_id.integer' => 'The selected bus is invalid.',
        ];
    }
}
