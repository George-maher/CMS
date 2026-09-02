<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isAdminOrAssistantAdmin() || $user->isServant());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
