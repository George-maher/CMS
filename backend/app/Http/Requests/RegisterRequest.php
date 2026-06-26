<?php

namespace App\Http\Requests;

use App\Rules\NotPlaceholder;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'email' => ['required', 'email', 'unique:users,email', new NotPlaceholder],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'invite_token' => ['required', 'string', 'size:64'],
            'birthday' => ['sometimes', 'date', 'before:today'],
            'class_id' => ['sometimes', 'integer', 'exists:classes,id'],
            'phone' => ['sometimes', 'digits:11', 'regex:/^[0-9]{11}$/', new NotPlaceholder],
            'address' => ['sometimes', 'string', 'max:500', new NotPlaceholder],
            'member_address' => ['sometimes', 'string', 'max:500', new NotPlaceholder],
        ];
    }
}
