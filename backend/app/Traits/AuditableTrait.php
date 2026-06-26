<?php

namespace App\Traits;

use App\Contracts\AuditServiceInterface;

trait AuditableTrait
{
    public static function bootAuditableTrait(): void
    {
        static::created(function ($model) {
            self::logAction('created', $model, null, $model->toArray());
        });

        static::updated(function ($model) {
            $changed = $model->getChanges();
            if (empty($changed)) {
                return;
            }

            $oldValues = array_intersect_key($model->getOriginal(), $changed);
            self::logAction('updated', $model, $oldValues, $changed);
        });

        static::deleted(function ($model) {
            self::logAction('deleted', $model, $model->toArray(), null);
        });
    }

    protected static function logAction(string $action, $model, ?array $oldValues, ?array $newValues): void
    {
        $service = app(AuditServiceInterface::class);
        $service->logModelAction($action, $model, $oldValues, $newValues);
    }
}
