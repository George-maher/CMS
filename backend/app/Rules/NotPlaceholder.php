<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NotPlaceholder implements ValidationRule
{
    private const PATTERNS = [
        '/^Example:\s/i',
        '/^مثال:\s/i',
        '/^e\.g\.\s/i',
        '/^مثل\s/i',
        '/^\+1\s\(555\)\s\d{3}-?\d{4}$/',
    ];

    private const EXACT_MATCHES = [
        '••••••••',
        'your@email.com',
        'support@churchplatform.local',
        'MBR-123456',
    ];

    private const PLACEHOLDER_INDICATORS = [
        'placeholder',
        'optional',
        'اختياري',
    ];

    private array $extraForbidden;

    public function __construct(array $extraForbidden = [])
    {
        $this->extraForbidden = $extraForbidden;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            return;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return;
        }

        if (in_array($trimmed, self::EXACT_MATCHES, true)) {
            $fail("The {$attribute} field contains a placeholder or example value and is not allowed.");
            return;
        }

        if (in_array($trimmed, $this->extraForbidden, true)) {
            $fail("The {$attribute} field contains a placeholder or example value and is not allowed.");
            return;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                $fail("The {$attribute} field contains a placeholder or example value and is not allowed.");
                return;
            }
        }

        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            $localPart = explode('@', $trimmed)[0] ?? '';
            if (in_array(strtolower($localPart), ['example', 'user', 'your', 'test', 'placeholder', 'name'], true)) {
                $fail("The {$attribute} field appears to be a placeholder email and is not allowed.");
                return;
            }
        }
    }
}
