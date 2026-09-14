<?php

namespace App\Http\Requests;

use App\Enums\QRInviteType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateQRInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();
        $churchId = $user?->church_id;

        return [
            'type' => ['required', Rule::in(QRInviteType::values())],
            'class_id' => ['nullable', 'integer', Rule::exists('classes', 'id')->where('church_id', $churchId)],
            'stage_id' => ['nullable', 'integer', Rule::exists('stages', 'id')->where('church_id', $churchId)],
            'attendance_context_id' => ['nullable', 'integer', Rule::exists('attendance_contexts', 'id')->where('church_id', $churchId)],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'client_request_id' => ['nullable', 'string', 'min:8', 'max:64'],
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
