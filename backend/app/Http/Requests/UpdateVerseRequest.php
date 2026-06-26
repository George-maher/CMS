<?php

namespace App\Http\Requests;

use App\Rules\NotPlaceholder;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVerseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verse_text' => ['sometimes', 'required', 'string', 'max:5000', new NotPlaceholder],
            'reference' => ['sometimes', 'required', 'string', 'max:255', new NotPlaceholder],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
