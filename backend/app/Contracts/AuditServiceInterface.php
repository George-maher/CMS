<?php

namespace App\Contracts;

interface AuditServiceInterface
{
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
    ): void;

    /** @param array<string, mixed>|null $oldValues */
    /** @param array<string, mixed>|null $newValues */
    public function logModelAction(
        string $action,
        object $model,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void;
}
