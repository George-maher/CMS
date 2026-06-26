<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bucket = $this->route('bucket');

        $imageBuckets = ['profiles', 'events'];
        $docBuckets = ['documents', 'ids', 'attachments'];

        if (in_array($bucket, $imageBuckets, true)) {
            return [
                'file' => [
                    'required',
                    'file',
                    'mimes:jpg,jpeg,png,gif,webp',
                    'max:' . config('supabase-storage.validation.max_image_size', 5120),
                ],
            ];
        }

        if (in_array($bucket, $docBuckets, true)) {
            return [
                'file' => [
                    'required',
                    'file',
                    'mimes:pdf,doc,docx,jpg,jpeg,png',
                    'max:' . config('supabase-storage.validation.max_document_size', 10240),
                ],
            ];
        }

        return [
            'file' => ['required', 'file', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => __('validation.required', ['attribute' => 'file']),
            'file.file' => __('validation.file', ['attribute' => 'file']),
            'file.mimes' => __('validation.mimes', ['attribute' => 'file']),
            'file.max' => __('validation.max.file', ['attribute' => 'file']),
        ];
    }
}
