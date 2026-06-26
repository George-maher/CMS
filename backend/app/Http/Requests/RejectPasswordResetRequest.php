<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectPasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminOrAssistantAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'reason' => 'rejection reason',
        ];
    }
}
