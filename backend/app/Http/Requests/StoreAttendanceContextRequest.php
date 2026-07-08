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

    public function rules(): array
    {
        $contextId = $this->route('id');
        $churchId = $this->user()?->church_id;

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
