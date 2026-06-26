<?php

namespace App\Http\Requests;

use App\Rules\NotPlaceholder;
use Illuminate\Foundation\Http\FormRequest;

class StoreVerseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verse_text' => ['required', 'string', 'max:5000', new NotPlaceholder],
            'reference' => ['required', 'string', 'max:255', new NotPlaceholder],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
