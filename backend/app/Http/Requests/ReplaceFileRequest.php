<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx', 'max:10240'],
            'old_url' => ['required', 'string', 'url'],
        ];
    }

    public function messages(): array
    {
        return [
            'old_url.required' => 'The old file URL is required to replace the file.',
            'old_url.url' => 'The old file URL must be a valid URL.',
        ];
    }
}
