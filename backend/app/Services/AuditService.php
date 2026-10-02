<?php

namespace App\Services;

use App\Contracts\AuditServiceInterface;
use App\Models\AuditLog;
use App\Models\User;

class AuditService implements AuditServiceInterface
{
    private const PII_FIELDS = [
        'password',
        'email',
        'phone',
        'address',
        'member_address',
        'attendance_qr_token',
        'email_verification_token',
        'remember_token',
    ];

    /** @param array<string, mixed>|null $oldValues */
    /** @param array<string, mixed>|null $newValues */
    public function log(
        string $action,
        string $resourceType,
        ?int $resourceId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
        ?int $churchId = null,
    ): void {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        if ($userId === null) {
            /** @var int|null $authId */
            $authId = auth()->id();
            $userId = $authId;
        }

        if ($churchId === null) {
            /** @var User|null $authUser */
            $authUser = auth()->user();
            $churchId = $authUser?->church_id;
        }

        /** @var string|null $ip */
        $ip = request()->ip();
        /** @var string|null $agent */
        $agent = request()->userAgent();

        AuditLog::create([
            'church_id' => $churchId,
            'user_id' => $userId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'old_values' => $this->maskPii($oldValues),
            'new_values' => $this->maskPii($newValues),
            'ip_address' => $ip,
            'user_agent' => $agent,
        ]);
    }

    /** @param array<string, mixed>|null $oldValues */
    /** @param array<string, mixed>|null $newValues */
    public function logModelAction(
        string $action,
        object $model,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        /** @var User|null $authUser */
        $authUser = auth()->user();
        $churchId = $authUser?->church_id;

        if (! $churchId && isset($model->church_id)) {
            $churchId = $model->church_id;
        }

        /** @var int|string|null $modelId */
        $modelId = property_exists($model, 'id') ? $model->id ?? null : null;
        /** @var int|null $resourceId */
        $resourceId = $modelId !== null ? intval($modelId) : null;

        /** @var int|null $auditChurchId */
        $auditChurchId = $churchId ?? null;
        $this->log(
            action: $action,
            resourceType: get_class($model),
            resourceId: $resourceId,
            oldValues: $oldValues,
            newValues: $newValues,
            churchId: $auditChurchId,
        );
    }

    /** @param array<string, mixed>|null $values */
    /** @return array<string, mixed>|null */
    private function maskPii(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $masked = [];
        foreach ($values as $key => $value) {
            if (in_array($key, self::PII_FIELDS, true) && $value !== null) {
                $masked[$key] = $this->maskValue($key, $value);
            } else {
                $masked[$key] = $this->sanitizeUtf8($value);
            }
        }

        /** @var array<string, mixed> $masked */
        return $masked;
    }

    /**
     * Recursively sanitize values to ensure valid UTF-8 encoding.
     */
    private function sanitizeUtf8(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            if (is_array($value)) {
                return array_map([$this, 'sanitizeUtf8'], $value);
            }

            return $value;
        }

        return $this->sanitizeString($value);
    }

    private function sanitizeString(string $str): string
    {
        // If already valid UTF-8, return as-is
        if (mb_check_encoding($str, 'UTF-8')) {
            return $str;
        }

        // Use iconv to strip invalid sequences (//IGNORE flag works with iconv)
        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $str);
        if ($sanitized !== false && mb_check_encoding($sanitized, 'UTF-8')) {
            return $sanitized;
        }

        // Fallback: remove control characters and try iconv again
        $sanitized = preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', $str) ?? '';
        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $sanitized);
        if ($sanitized !== false && mb_check_encoding($sanitized, 'UTF-8')) {
            return $sanitized;
        }

        // Last resort: manually filter valid UTF-8 bytes
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

    private function maskValue(string $field, mixed $value): string
    {
        if (! is_string($value) || strlen($value) === 0) {
            return '***masked***';
        }

        return match ($field) {
            'password' => '***masked***',
            'email' => $this->maskEmail((string) $value),
            'phone' => $this->maskPhone((string) $value),
            'attendance_qr_token', 'email_verification_token', 'remember_token' => '***masked***',
            'address', 'member_address' => strlen((string) $value) > 10 ? substr((string) $value, 0, 5).'...' : '***masked***',
            default => '***masked***',
        };
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        $name = $parts[0] ?? '';
        $domain = $parts[1] ?? '';
        $visible = min(2, (int) ceil(strlen($name) / 3));
        $masked = substr($name, 0, $visible).str_repeat('*', strlen($name) - $visible);

        return $masked.'@'.$domain;
    }

    private function maskPhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        $cleanedStr = strval($cleaned);
        $len = strlen($cleanedStr);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($cleanedStr, 0, 3).str_repeat('*', $len - 6).substr($cleanedStr, -3);
    }
}
