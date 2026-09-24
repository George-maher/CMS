<?php

namespace App\Models\Scopes;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class ChurchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::check() && app()->bound('request') && request()->route() !== null) {
            // An unauthenticated web request has no trusted tenant context.
            // Public token flows must explicitly opt out and authorize the
            // token/resource relationship themselves.
            $builder->whereRaw('1 = 0');

            return;
        }

        $churchId = $this->resolveChurchId();

        if ($churchId === null) {
            return;
        }

        $builder->where($model->getTable().'.church_id', $churchId);
    }

    private function resolveChurchId(): ?int
    {
        // 1. Authenticated user
        /** @var User|null $user */
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

        // No authenticated tenant context is available. Do not infer one from
        // client-controlled headers; public token-based flows must opt out of
        // this scope explicitly and apply their own resource authorization.
        return null;
    }
}
