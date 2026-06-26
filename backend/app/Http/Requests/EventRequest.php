<?php

namespace App\Http\Requests;

use App\Enums\EventType;
use App\Rules\NotPlaceholder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PATCH') || $this->isMethod('PUT');

        $rules = [
            'name' => $isUpdate ? ['sometimes', 'string', 'max:255', new NotPlaceholder] : ['required', 'string', 'max:255', new NotPlaceholder],
            'type' => $isUpdate ? ['sometimes', Rule::in(EventType::values())] : ['required', Rule::in(EventType::values())],
            'image' => $isUpdate ? ['sometimes', 'nullable', 'file', 'max:' . config('supabase-storage.validation.max_image_size', 5120), $this->imageRule()] : ['nullable', 'file', 'max:' . config('supabase-storage.validation.max_image_size', 5120), $this->imageRule()],
            'description' => $isUpdate ? ['sometimes', 'nullable', 'string'] : ['nullable', 'string'],
            'event_date' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255', new NotPlaceholder],
            'is_all_classes' => ['sometimes', 'boolean'],
            'target_class_ids' => ['sometimes', 'array'],
            'target_class_ids.*' => ['integer', 'exists:classes,id'],
        ];

        if ($isUpdate) {
            $rules['is_active'] = ['sometimes', 'boolean'];
            $rules['class_id'] = ['sometimes', 'nullable', 'integer', 'exists:classes,id'];
            $rules['remove_image'] = ['sometimes', 'boolean'];
        } else {
            $rules['is_active'] = ['required', 'boolean'];
            $rules['class_id'] = ['nullable', 'integer', 'exists:classes,id'];
        }

        return $rules;
    }

    private function imageRule(): callable
    {
        return function ($attribute, $value, $fail) {
            if (!$value instanceof UploadedFile) {
                return;
            }
            $ext = strtolower($value->getClientOriginalExtension());
            $allowed = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif'];
            if (!in_array($ext, $allowed, true)) {
                $fail("The {$attribute} must be a file of type: jpeg, png, jpg, gif, webp.");
            }
            $allowedMimes = [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/avif',
            ];
            $mime = $value->getMimeType();
            if (!in_array($mime, $allowedMimes, true)) {
                $fail("The {$attribute} has an invalid file type.");
            }
        };
    }
}
