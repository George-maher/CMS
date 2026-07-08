<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PhoneRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = preg_replace('/\s+/', '', $value ?? '');

        if (!preg_match('/^\d{11}$/', $value)) {
            $fail(__('validation.phone_exact_11'))->translate();
        }
    }
}
