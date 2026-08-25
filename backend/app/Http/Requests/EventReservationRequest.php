<?php

namespace App\Http\Requests;

use App\Enums\RegistrationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventReservationRequest extends FormRequest
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
            'status' => [
                'required',
                'string',
                Rule::in([
                    RegistrationStatus::Booked->value,
                    RegistrationStatus::NotReserved->value,
                    RegistrationStatus::Thinking->value,
                ]),
            ],
            'booked_with' => ['nullable', 'string', 'max:255'],
            'amount_paid' => ['nullable', 'string', 'regex:/^[\d.]+$/'],
            'medical_notes' => ['nullable', 'string', 'max:1000'],
            'medication_time' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'event_id.required' => 'Event is required.',
            'event_id.integer' => 'Event must be a valid number.',
            'event_id.exists' => 'Event not found.',

            'status.required' => 'Reservation status is required.',
            'status.in' => 'Invalid reservation status.',

            'booked_with.max' => 'Booked with must not exceed 255 characters.',

            'amount_paid.regex' => 'Amount paid must be a valid number.',

            'medical_notes.max' => 'Medical notes must not exceed 1000 characters.',

            'medication_time.max' => 'Medication time must not exceed 100 characters.',
        ];
    }
}
