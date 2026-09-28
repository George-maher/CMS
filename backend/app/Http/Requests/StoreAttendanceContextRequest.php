<?php

namespace App\Http\Requests;

use App\Rules\NotPlaceholder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreAttendanceContextRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var \App\Models\User $user */
        $user = $this->user();
        $churchId = $user->church_id;
        $contextId = $this->route('id');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                new NotPlaceholder,
                Rule::unique('attendance_contexts')
                    ->where('church_id', $churchId)
                    ->ignore($contextId),
            ],
            'name_ar' => ['nullable', 'string', 'max:255', new NotPlaceholder],
            'description' => ['nullable', 'string', 'max:1000', new NotPlaceholder],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
