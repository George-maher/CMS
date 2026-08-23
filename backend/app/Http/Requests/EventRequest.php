<?php

namespace App\Http\Requests;

use App\Enums\EventStatus;
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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PATCH') || $this->isMethod('PUT');
        /** @var int $maxImageSize */
        $maxImageSize = config('supabase-storage.validation.max_image_size', 5120);

        $rules = [
            'name' => $isUpdate ? ['sometimes', 'string', 'max:255', new NotPlaceholder] : ['required', 'string', 'max:255', new NotPlaceholder],
            'type' => $isUpdate ? ['sometimes', Rule::in(EventType::values())] : ['required', Rule::in(EventType::values())],
            'status' => ['sometimes', Rule::in(EventStatus::values())],
            'image' => $isUpdate ? ['sometimes', 'nullable', 'file', 'max:'.$maxImageSize, $this->imageRule()] : ['nullable', 'file', 'max:'.$maxImageSize, $this->imageRule()],
            'description' => $isUpdate ? ['sometimes', 'nullable', 'string'] : ['nullable', 'string'],
            'event_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:event_date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'max_capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'location' => ['nullable', 'string', 'max:255', new NotPlaceholder],
            'is_all_classes' => ['sometimes', 'boolean'],
            'target_class_ids' => ['sometimes', 'array'],
            'target_class_ids.*' => ['integer', 'exists:classes,id'],

            // Conference-specific
            'theme' => ['nullable', 'string', 'max:255'],
            'target_age_group' => ['nullable', 'string', 'max:100'],
            'target_group' => ['nullable', 'string', 'max:255'],

            // Trip-specific
            'destination' => ['nullable', 'string', 'max:255'],
            'departure_location' => ['nullable', 'string', 'max:255'],
            'departure_at' => ['nullable', 'date'],
            'return_at' => ['nullable', 'date', 'after_or_equal:departure_at'],
            'transportation_type' => ['nullable', 'string', 'max:100'],
            'coordinator_name' => ['nullable', 'string', 'max:255'],
            'coordinator_phone' => ['nullable', 'string', 'max:30'],
            'price_per_participant' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
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
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (! $value instanceof UploadedFile) {
                return;
            }
            $ext = strtolower($value->getClientOriginalExtension());
            $allowed = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif'];
            if (! in_array($ext, $allowed, true)) {
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
            if (! in_array($mime, $allowedMimes, true)) {
                $fail("The {$attribute} has an invalid file type.");
            }
        };
    }
}
