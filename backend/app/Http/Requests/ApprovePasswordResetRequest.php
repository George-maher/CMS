<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApprovePasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminOrAssistantAdmin() ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
