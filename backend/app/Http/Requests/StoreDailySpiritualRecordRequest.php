<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDailySpiritualRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'activity_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'attended_mass' => ['sometimes', 'boolean'],
            'confessed' => ['sometimes', 'boolean'],
            'received_communion' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'activity_date.required' => 'The activity date is required.',
            'activity_date.date_format' => 'The activity date must be in Y-m-d format.',
            'activity_date.before_or_equal' => 'Future dates are not allowed.',
        ];
    }
}
