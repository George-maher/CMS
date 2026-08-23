<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventBusRequest extends FormRequest
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
            'bus_number' => $isUpdate ? ['sometimes', 'string', 'max:50'] : ['required', 'string', 'max:50'],
            'capacity' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'min:1', 'max:500'],
            'driver_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'coordinator_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
