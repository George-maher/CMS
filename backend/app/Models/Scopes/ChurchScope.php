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

        // Fail closed for an authenticated user that belongs to no church.
        //
        // This branch used to be missing, and its absence was a real
        // cross-tenant disclosure. `resolveChurchId()` returns null both for
        // "platform admin, see everything" and for "this user has no
        // church_id", and null meant "apply no filter at all". An approved
        // non-platform user with church_id = NULL therefore read every
        // tenant's rows on every scoped model:
        //
        //   GET /api/v1/stages  ->  200, containing other churches' stages
        //
        // `StageController::show()` happened to be safe only because
        // ScopeResolver::canAccessStage() re-checks church_id afterwards.
        // List endpoints have no such second check, so the scope itself has to
        // deny the rows.
        //
        // `church_id` is a serial starting at 1, so 0 can never match a real
        // church and is a safe "no tenant" marker.
        if (Auth::check() && ! $this->isPlatformAdmin() && $this->resolveChurchId() === null) {
            $builder->where($model->getTable().'.church_id', 0);

            return;
        }

        $churchId = $this->resolveChurchId();

        if ($churchId === null) {
            return;
        }

        $builder->where($model->getTable().'.church_id', $churchId);
    }

    private function isPlatformAdmin(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user !== null && $user->role === UserRole::PlatformAdmin;
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
