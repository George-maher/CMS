<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RejectReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'registration_id' => [
                'required',
                'integer',
                Rule::exists('event_registrations', 'id'),
            ],
            'rejection_reason' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }
}
