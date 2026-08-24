<?php

namespace App\Http\Requests;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\User;
use App\Rules\NotPlaceholder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

            // Responsible servant (conference/trip) — must be active Servant from same church
            'responsible_servant_id' => [
                'required_if:type,'.EventType::Conference->value.','.EventType::Trip->value,
                'integer',
                'exists:users,id',
            ],

            // Bulk room configuration (conference/trip)
            'total_rooms' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'room_groups' => ['sometimes', 'array'],
            'room_groups.*.count' => ['required_with:room_groups', 'integer', 'min:1', 'max:1000'],
            'room_groups.*.capacity' => ['required_with:room_groups', 'integer', 'min:2', 'max:100'],

            // Per-bus configuration (conference/trip)
            'bus_config' => ['sometimes', 'array'],
            'bus_config.*.capacity' => ['required_with:bus_config', 'integer', 'min:1', 'max:200'],
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

    /**
     * Ensure sum of room group counts equals total_rooms when both are provided.
     * Ensure responsible_servant_id is an active Servant from the same church.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var mixed $totalRooms */
            $totalRooms = $this->input('total_rooms');
            /** @var mixed $roomGroups */
            $roomGroups = $this->input('room_groups');

            if ($totalRooms !== null && is_array($roomGroups) && count($roomGroups) > 0 && is_numeric($totalRooms)) {
                /** @var array<int, array{count: int}> $roomGroups */
                $sum = (int) array_sum(array_column($roomGroups, 'count'));
                $total = (int) $totalRooms;
                if ($sum !== $total) {
                    $validator->errors()->add(
                        'room_groups',
                        "Room group counts ({$sum}) must equal total rooms ({$total})."
                    );
                }
            }

            // Validate responsible_servant_id: must be active Servant from same church
            /** @var mixed $servantId */
            $servantId = $this->input('responsible_servant_id');
            if ($servantId !== null && $servantId !== '' && is_numeric($servantId)) {
                /** @var User|null $authUser */
                $authUser = $this->user();
                $churchId = $authUser?->church_id;

                $servant = User::query()
                    ->where('id', (int) $servantId)
                    ->first();

                if (! $servant) {
                    $validator->errors()->add('responsible_servant_id', 'The selected user does not exist.');
                } elseif ($servant->role->value !== 'servant') {
                    $validator->errors()->add('responsible_servant_id', 'The selected user must be a Servant.');
                } elseif (! $servant->is_active) {
                    $validator->errors()->add('responsible_servant_id', 'The selected Servant is inactive.');
                } elseif ($churchId !== null && $servant->church_id !== $churchId) {
                    $validator->errors()->add('responsible_servant_id', 'The selected Servant does not belong to your Church.');
                }
            }
        });
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
