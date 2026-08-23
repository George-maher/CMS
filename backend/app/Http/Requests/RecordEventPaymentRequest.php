<?php

namespace App\Http\Requests;

use App\Enums\EventPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordEventPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'method' => ['required', Rule::in(EventPaymentMethod::values())],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
