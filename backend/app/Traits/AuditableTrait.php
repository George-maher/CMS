<?php

namespace App\Traits;

use App\Contracts\AuditServiceInterface;

trait AuditableTrait
{
    public static function bootAuditableTrait(): void
    {
        static::created(function (\Illuminate\Database\Eloquent\Model $model) {
            /** @var array<string, mixed> $newValues */
            $newValues = $model->toArray();
            static::logAction('created', $model, null, $newValues);
        });

        static::updated(function (\Illuminate\Database\Eloquent\Model $model) {
            /** @var array<string, mixed> $changed */
            $changed = $model->getChanges();
            if (empty($changed)) {
                return;
            }

            /** @var array<string, mixed> $original */
            $original = $model->getOriginal();
            $oldValues = array_intersect_key($original, $changed);
            static::logAction('updated', $model, $oldValues, $changed);
        });

        static::deleted(function (\Illuminate\Database\Eloquent\Model $model) {
            /** @var array<string, mixed> $oldValues */
            $oldValues = $model->toArray();
            static::logAction('deleted', $model, $oldValues, null);
        });
    }

    /**
     * @param object $model
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    protected static function logAction(string $action, $model, ?array $oldValues, ?array $newValues): void
    {
        $service = app(AuditServiceInterface::class);
        $service->logModelAction($action, $model, $oldValues, $newValues);
    }
}
