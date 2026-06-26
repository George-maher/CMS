<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string'],
            'event_id' => ['sometimes', 'integer', 'exists:events,id'],
            'attendance_context_id' => ['required', 'integer', 'exists:attendance_contexts,id'],
        ];
    }
}
