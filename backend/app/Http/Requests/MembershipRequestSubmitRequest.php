<?php

namespace App\Http\Requests;

use App\Rules\NotPlaceholder;
use App\Rules\PhoneRule;
use Illuminate\Foundation\Http\FormRequest;

class MembershipRequestSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'church_id' => ['required', 'integer', 'exists:churches,id'],
            'name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'email' => ['required', 'email', 'max:255', new NotPlaceholder],
            'phone' => ['nullable', new PhoneRule, new NotPlaceholder],
            'birthday' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:500', new NotPlaceholder],
            'preferred_role' => ['sometimes', 'string', 'in:member,servant'],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,doc,docx', 'max:10240'],
        ];
    }
}
