<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|null>
 */
class AuditLogValues implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>|null  $value
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>|null  $value
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Ensure all string values are valid UTF-8 before JSON encoding
        $sanitized = $this->sanitizeArray($value);

        // Use JSON_INVALID_UTF8_SUBSTITUTE to replace invalid sequences instead of failing
        $json = @json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
            // Last resort: aggressively sanitize and try again
            $sanitized = $this->aggressiveSanitize($sanitized);
            $json = @json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
                // If still failing, return empty object to not break the transaction
                $json = '{}';
            }
        }

        return $json;
    }

    /**
     * Recursively sanitize array values to ensure valid UTF-8.
     *
     * @param  array<mixed, mixed>  $array
     * @return array<mixed, mixed>
     */
    private function sanitizeArray(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $result[$key] = $this->sanitizeValue($value);
        }

        return $result;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        if (is_array($value)) {
            return $this->sanitizeArray($value);
        }

        // Numbers, booleans, objects - return as-is
        return $value;
    }

    private function sanitizeString(string $str): string
    {
        // Quick check - if valid UTF-8, return as-is
        if (mb_check_encoding($str, 'UTF-8')) {
            return $str;
        }

        // Use iconv to strip invalid sequences
        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $str);
        if ($sanitized !== false && mb_check_encoding($sanitized, 'UTF-8')) {
            return $sanitized;
        }

        // Fallback: remove control characters and try again
        $sanitized = preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', $str) ?? '';
        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $sanitized);
        if ($sanitized !== false && mb_check_encoding($sanitized, 'UTF-8')) {
            return $sanitized;
        }

        // Last resort: manually filter valid UTF-8 bytes
        return $this->manualUtf8Filter($str);
    }

    /**
     * Aggressive sanitization for when JSON encoding still fails.
     *
     * @param  array<mixed, mixed>  $array
     * @return array<mixed, mixed>
     */
    private function aggressiveSanitize(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_string($value)) {
                // Strip all non-ASCII as last resort
                /** @var string $sanitized */
                $sanitized = preg_replace('/[^\x20-\x7E]/', '', $value);
                $result[$key] = $sanitized;
            } elseif (is_array($value)) {
                $result[$key] = $this->aggressiveSanitize($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Manually filter bytes to keep only valid UTF-8 sequences.
     */
    private function manualUtf8Filter(string $str): string
    {
        $result = '';
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $byte = ord($str[$i]);

            if ($byte < 0x80) {
                // ASCII (0xxxxxxx)
                $result .= $str[$i];
            } elseif ($byte < 0xC0) {
                // Continuation byte (10xxxxxx) - skip orphaned
                continue;
            } elseif ($byte < 0xE0) {
                // 2-byte sequence (110xxxxx 10xxxxxx)
                if ($i + 1 < $len && (ord($str[$i + 1]) & 0xC0) === 0x80) {
                    $result .= $str[$i].$str[$i + 1];
                    $i++;
                }
            } elseif ($byte < 0xF0) {
                // 3-byte sequence (1110xxxx 10xxxxxx 10xxxxxx)
                if ($i + 2 < $len
                    && (ord($str[$i + 1]) & 0xC0) === 0x80
                    && (ord($str[$i + 2]) & 0xC0) === 0x80) {
                    $result .= $str[$i].$str[$i + 1].$str[$i + 2];
                    $i += 2;
                }
            } elseif ($byte < 0xF8) {
                // 4-byte sequence (11110xxx 10xxxxxx 10xxxxxx 10xxxxxx)
                if ($i + 3 < $len
                    && (ord($str[$i + 1]) & 0xC0) === 0x80
                    && (ord($str[$i + 2]) & 0xC0) === 0x80
                    && (ord($str[$i + 3]) & 0xC0) === 0x80) {
                    $result .= $str[$i].$str[$i + 1].$str[$i + 2].$str[$i + 3];
                    $i += 3;
                }
            }
        }

        return $result;
    }
}
