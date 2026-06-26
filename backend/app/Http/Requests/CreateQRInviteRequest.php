<?php

namespace App\Http\Requests;

use App\Enums\QRInviteType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateQRInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(QRInviteType::values())],
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'attendance_context_id' => ['nullable', 'integer', 'exists:attendance_contexts,id'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ];
    }

    public function prepareForValidation(): void
    {
        if ($this->has('max_uses') && $this->input('max_uses') === '') {
            $this->merge(['max_uses' => null]);
        }
        if ($this->has('expires_in_hours') && $this->input('expires_in_hours') === '') {
            $this->merge(['expires_in_hours' => null]);
        }
    }
}
