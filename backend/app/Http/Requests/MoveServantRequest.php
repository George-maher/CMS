<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Moves one servant assignment from `from_class_id` to the class in the URL.
 *
 * Both classes are re-resolved server-side and must be inside the actor's
 * scope and the same stage; the ids here are routing hints, never
 * authorization evidence.
 */
class MoveServantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'from_class_id' => ['required', 'integer', 'exists:classes,id'],
        ];
    }
}
