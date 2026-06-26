<?php

namespace App\Http\Requests;

use App\Enums\FeedbackCategory;
use App\Enums\UserRole;
use App\Rules\NotPlaceholder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Member;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:10', 'max:5000', new NotPlaceholder],
            'category' => ['sometimes', Rule::in(FeedbackCategory::values())],
            'is_anonymous' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_anonymous') && $this->input('is_anonymous') === '') {
            $this->merge(['is_anonymous' => false]);
        }
    }
}
