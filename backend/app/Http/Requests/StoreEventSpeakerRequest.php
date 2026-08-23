<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventSpeakerRequest extends FormRequest
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
            'name' => $isUpdate ? ['sometimes', 'string', 'max:255'] : ['required', 'string', 'max:255'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
