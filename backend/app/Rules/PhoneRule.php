<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhoneRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) && !is_numeric($value)) {
            return;
        }

        /** @var string $stringValue */
        $stringValue = (string) $value;
        $cleaned = preg_replace('/\s+/', '', $stringValue);

        if ($cleaned !== null && !preg_match('/^\d{11}$/', $cleaned)) {
            $fail(__('validation.phone_exact_11'))->translate();
        }
    }
}
