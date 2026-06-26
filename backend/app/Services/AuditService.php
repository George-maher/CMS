<?php

namespace App\Services;

use App\Contracts\AuditServiceInterface;
use App\Models\AuditLog;

class AuditService implements AuditServiceInterface
{
    private const array PII_FIELDS = [
        'password',
        'email',
        'phone',
        'address',
        'member_address',
        'attendance_qr_token',
        'email_verification_token',
        'remember_token',
    ];

    public function log(
        string $action,
        string $resourceType,
        ?int $resourceId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
        ?int $churchId = null,
    ): void {
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            return;
        }

        $userId = $userId ?? auth()->id();
        $churchId = $churchId ?? auth()->user()?->church_id;

        AuditLog::create([
            'church_id' => $churchId,
            'user_id' => $userId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'old_values' => $this->maskPii($oldValues),
            'new_values' => $this->maskPii($newValues),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    public function logModelAction(
        string $action,
        object $model,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        $churchId = auth()->user()?->church_id;
        if (!$churchId && isset($model->church_id)) {
            $churchId = $model->church_id;
        }

        $this->log(
            action: $action,
            resourceType: get_class($model),
            resourceId: $model->id ?? null,
            oldValues: $oldValues,
            newValues: $newValues,
            churchId: $churchId,
        );
    }

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

        return $masked;
    }

    private function maskValue(string $field, mixed $value): string
    {
        if (!is_string($value) || strlen($value) === 0) {
            return '***masked***';
        }

        return match ($field) {
            'password' => '***masked***',
            'email' => $this->maskEmail($value),
            'phone' => $this->maskPhone($value),
            'attendance_qr_token', 'email_verification_token', 'remember_token' => '***masked***',
            'address', 'member_address' => strlen($value) > 10 ? substr($value, 0, 5) . '...' : '***masked***',
            default => '***masked***',
        };
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        $name = $parts[0] ?? '';
        $domain = $parts[1] ?? '';
        $visible = min(2, (int) ceil(strlen($name) / 3));
        $masked = substr($name, 0, $visible) . str_repeat('*', strlen($name) - $visible);

        return $masked . '@' . $domain;
    }

    private function maskPhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        $len = strlen($cleaned);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($cleaned, 0, 3) . str_repeat('*', $len - 6) . substr($cleaned, -3);
    }
}
