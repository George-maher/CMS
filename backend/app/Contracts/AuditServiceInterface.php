<?php

namespace App\Contracts;

interface AuditServiceInterface
{
    public function log(
        string $action,
        string $resourceType,
        ?int $resourceId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
        ?int $churchId = null,
    ): void;

    public function logModelAction(
        string $action,
        object $model,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void;
}
