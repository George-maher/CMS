<?php

namespace App\Traits;

use App\Models\Church;
use App\Models\Scopes\ChurchScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToChurch
{
    public static function bootBelongsToChurch(): void
    {
        static::addGlobalScope(new ChurchScope);

        static::creating(function (Model $model) {
            if ($model->getAttribute('church_id')) {
                return;
            }

            // Resolve from authenticated user
            if (auth()->check()) {
                /** @var User|null $user */
                $user = auth()->user();
                if ($user && $user->church_id) {
                    $model->setAttribute('church_id', $user->church_id);

                    return;
                }
            }

            // Resolve from HTTP header
            if (! app()->runningInConsole() && request()->hasHeader('X-Church-ID')) {
                $model->setAttribute('church_id', intval(request()->header('X-Church-ID')));

                return;
            }

            // Allow models without church_id only if explicitly opted in
            // (e.g., PlatformAdmin-created records, church creation itself)
        });
    }

    /** @return BelongsTo<Church, $this> */
    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeByChurch($query, ?int $churchId = null): Builder
    {
        $churchId = $churchId ?? auth()->user()?->church_id;
        if ($churchId) {
            return $query->where($query->getModel()->getTable().'.church_id', $churchId);
        }

        return $query;
    }
}
