<?php

namespace App\Modules\User\Policies;

use App\Contracts\ScopeResolverInterface;
use App\Models\User;

class UserPolicy
{
    public function __construct(
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStageAdmin() || $user->isServant();
    }

    public function view(User $user, User $target): bool
    {
        return $this->scopeResolver->canAccessUser($user, $target);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isStageAdmin();
    }

    public function update(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return true;
        }

        return ($user->isAdmin() || $user->isStageAdmin())
            && $this->scopeResolver->canAccessUser($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }
        if ($target->isAdmin() || $target->isPlatformAdmin()) {
            return false;
        }
        if ($user->church_id !== null && $user->church_id !== $target->church_id) {
            return false;
        }

        return true;
    }

    public function promote(User $user, User $target): bool
    {
        if (! $this->canManageUserRoles($user, $target)) {
            return false;
        }

        // Stage admins may only grant non-privileged roles (enforced upstream).
        return $user->isAdmin() || $user->isStageAdmin();
    }

    public function demote(User $user, User $target): bool
    {
        if (! $this->canManageUserRoles($user, $target)) {
            return false;
        }

        return $user->isAdmin() || $user->isStageAdmin();
    }

    private function canManageUserRoles(User $user, User $target): bool
    {
        if ($target->isPlatformAdmin()) {
            return false;
        }
        if (! $this->scopeResolver->canAccessUser($user, $target)) {
            return false;
        }

        return true;
    }

    public function viewMembers(User $user, ?User $servant = null): bool
    {
        if ($user->isAdmin() || $user->isStageAdmin()) {
            return true;
        }
        if ($user->isServant() && $servant && $user->id === $servant->id) {
            return true;
        }

        return false;
    }

    public function viewServants(User $user): bool
    {
        return $user->isAdmin() || $user->isStageAdmin();
    }

    public function regenerateQrToken(User $user, User $target): bool
    {
        if ($user->isAdmin() || $user->isStageAdmin()) {
            return $this->scopeResolver->canAccessUser($user, $target);
        }

        return $user->id === $target->id;
    }
}
