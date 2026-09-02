<?php

namespace App\Http\Requests;

use App\Rules\NotPlaceholder;
use Illuminate\Foundation\Http\FormRequest;

class StoreProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isMember() || $user->isServant());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', new NotPlaceholder],
            'phone' => ['sometimes', 'required', 'string', 'max:20', new NotPlaceholder],
            'email' => ['sometimes', 'required', 'email', new NotPlaceholder],
            'address' => ['sometimes', 'nullable', 'string', 'max:500', new NotPlaceholder],
        ];
    }
}
