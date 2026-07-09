<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bucket = $this->input('bucket', 'profiles');
        $docBuckets = ['documents', 'ids'];
        /** @var int $maxImageSize */
        $maxImageSize = config('supabase-storage.validation.max_image_size', 5120);
        /** @var int $maxDocumentSize */
        $maxDocumentSize = config('supabase-storage.validation.max_document_size', 10240);

        if (in_array($bucket, $docBuckets, true)) {
            return [
                'file' => [
                    'required',
                    'file',
                    'mimes:pdf,doc,docx,jpg,jpeg,png',
                    'max:'.$maxDocumentSize,
                ],
            ];
        }

        return [
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,gif,webp',
                'max:'.$maxImageSize,
            ],
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
