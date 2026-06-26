<?php

namespace App\Traits;

use App\Models\Church;
use App\Models\Scopes\ChurchScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToChurch
{
    public static function bootBelongsToChurch(): void
    {
        static::addGlobalScope(new ChurchScope);

        static::creating(function ($model) {
            if ($model->church_id) {
                return;
            }

            // Resolve from authenticated user
            if (auth()->check()) {
                $user = auth()->user();
                if ($user->church_id) {
                    $model->church_id = $user->church_id;
                    return;
                }
            }

            // Resolve from HTTP header
            if (!app()->runningInConsole() && request()->hasHeader('X-Church-ID')) {
                $model->church_id = (int) request()->header('X-Church-ID');
                return;
            }

            // Allow models without church_id only if explicitly opted in
            // (e.g., PlatformAdmin-created records, church creation itself)
        });
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class);
    }

    public function scopeByChurch($query, ?int $churchId = null)
    {
        $churchId = $churchId ?? auth()->user()?->church_id;
        if ($churchId) {
            return $query->where($query->getModel()->getTable() . '.church_id', $churchId);
        }
        return $query;
    }
}
