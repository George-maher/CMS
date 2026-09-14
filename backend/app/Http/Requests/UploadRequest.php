<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bucket = $this->effectiveBucket();
        $docBuckets = ['documents', 'ids', 'attachments'];
        /** @var int $maxImageSize */
        $maxImageSize = config('supabase-storage.validation.max_image_size', 5120);
        /** @var int $maxDocumentSize */
        $maxDocumentSize = config('supabase-storage.validation.max_document_size', 10240);

        $rules = [
            'bucket' => ['sometimes', 'string', Rule::in(['profiles', 'events', 'documents', 'ids', 'attachments'])],
        ];

        // The document/image branch is derived from the ACTUAL bucket:
        //   - route parameter for /storage/upload/{bucket}, replace/{bucket}
        //   - body input for /storage/upload-document
        $rules['file'] = in_array($bucket, $docBuckets, true)
            ? ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:'.$maxDocumentSize]
            : ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.$maxImageSize];

        return $rules;
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

    private function effectiveBucket(): string
    {
        /** @var string|null $routeBucket */
        $routeBucket = $this->route('bucket');
        /** @var string $bucketInput */
        $bucketInput = $this->input('bucket', 'profiles');
        $bucket = $routeBucket ?? $bucketInput;

        return strtolower(trim($bucket, ". \t\n\r\0\x0B/"));
    }
}
