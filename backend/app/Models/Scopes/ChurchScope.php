<?php

namespace App\Models\Scopes;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class ChurchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $churchId = $this->resolveChurchId();

        if ($churchId === null) {
            return;
        }

        $builder->where($model->getTable() . '.church_id', $churchId);
    }

    private function resolveChurchId(): ?int
    {
        // 1. Authenticated user
        $user = Auth::user();
        if ($user) {
            if ($user->role === UserRole::PlatformAdmin) {
                return null; // Platform admin sees all
            }
            if ($user->church_id) {
                return (int) $user->church_id;
            }
            return null;
        }

        // 2. HTTP request — try X-Church-ID header for public endpoints
        if (app()->runningInConsole()) {
            return null; // CLI/Queue — no automatic scoping
        }

        $request = request();
        if ($request && $request->hasHeader('X-Church-ID')) {
            return (int) $request->header('X-Church-ID');
        }

        // 3. No tenant context available
        // Return null — the scope will not apply (no filtering)
        // Callers should use withoutGlobalScope() for public token-based lookups
        return null;
    }
}
