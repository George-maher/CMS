<?php

namespace App\Modules\User\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in([
                UserRole::Admin->value,
                UserRole::AssistantAdmin->value,
                UserRole::StageAdmin->value,
                UserRole::Servant->value,
                UserRole::Member->value,
            ])],
            'stage_id' => ['nullable', 'integer', 'exists:stages,id'],
        ];
    }
}
