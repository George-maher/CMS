<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddBonusPointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'points' => ['required', 'integer', 'min:1', 'max:999999'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
