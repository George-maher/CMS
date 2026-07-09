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
                $masked[$key] = $value;
            }
        }

        /** @var array<string, mixed> $masked */
        return $masked;
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
