<?php

namespace App\Http\Requests;

use App\Rules\NotPlaceholder;
use Illuminate\Foundation\Http\FormRequest;

class StoreClasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stage_id' => ['required', 'integer', 'exists:stages,id'],
            'name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'description' => ['nullable', 'string', new NotPlaceholder],
        ];
    }
}
