<?php

namespace App\Http\Requests;

use App\Enums\EventAttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'attendance_status' => ['sometimes', Rule::in(EventAttendanceStatus::values())],
        ];
    }
}
