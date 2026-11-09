<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural validation only: the class is resolved from the route and the
 * policy decides whether the actor may manage it. The candidate is resolved
 * again inside the actor's church in ClasseService::assignSecretary(), so a
 * user id from another tenant is never trusted even if it passes `exists`.
 */
class AssignSecretaryRequest extends FormRequest
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
        ];
    }
}
