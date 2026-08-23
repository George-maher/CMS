<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreEventSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PATCH') || $this->isMethod('PUT');

        return [
            'title' => $isUpdate ? ['sometimes', 'string', 'max:255'] : ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'speaker_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => [
                'sometimes',
                'nullable',
                'date',
            ],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /**
     * Validate that ends_at is not before starts_at when both are present.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $startsAt = $this->input('starts_at');
            $endsAt = $this->input('ends_at');

            if (! is_string($startsAt) || ! is_string($endsAt) || $startsAt === '' || $endsAt === '') {
                return;
            }

            if (strtotime($endsAt) < strtotime($startsAt)) {
                $validator->errors()->add(
                    'ends_at',
                    'The session end time must be after the session start time.'
                );
            }
        });
    }
}
