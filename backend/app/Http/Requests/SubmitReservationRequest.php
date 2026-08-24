<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'event_id' => [
                'required',
                'integer',
                Rule::exists('events', 'id'),
            ],
            'booking_with' => [
                'required',
                'string',
                'max:255',
            ],
            'number_of_people' => [
                'required',
                'integer',
                'min:1',
            ],
            'amount' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'medical_notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}