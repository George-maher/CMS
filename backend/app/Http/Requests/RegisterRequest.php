<?php

namespace App\Http\Requests;

use App\Rules\NotPlaceholder;
use App\Rules\PhoneRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $email = $this->input('email');
            if (is_string($email)) {
                $this->merge([
                    'email' => strtolower(trim($email)),
                ]);
            }
        }

        if (empty($this->all()) && app()->runningInConsole()) {
            $this->initializeFromCurrentRequest();
        }
    }

    private function initializeFromCurrentRequest(): void
    {
        $current = request();
        if ($current && $current !== $this) {
            $this->initialize(
                $current->query->all(),
                $current->request->all(),
                $current->attributes->all(),
                $current->cookies->all(),
                $current->files->all(),
                $current->server->all(),
            );
            $this->headers = $current->headers;
            $this->content = $current->getContent();
            $this->setJson($current->json());
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new NotPlaceholder],
            'email' => ['required', 'email', 'unique:users,email', new NotPlaceholder],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'invite_token' => ['required', 'string', 'size:64'],
            'birthday' => ['sometimes', 'date', 'before:today'],
            'class_id' => ['sometimes', 'integer', 'exists:classes,id'],
            'phone' => ['sometimes', new PhoneRule, new NotPlaceholder],
            'address' => ['sometimes', 'string', 'max:500', new NotPlaceholder],
            'member_address' => ['sometimes', 'string', 'max:500', new NotPlaceholder],
        ];
    }
}
