<?php

namespace App\Modules\User\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $target): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isServant() && $target->isMember()) {
            return $target->church_id === $user->church_id
                && $target->class_year_id === $user->class_year_id;
        }
        return $user->id === $target->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        if ($user->isAdmin()) return true;
        return $user->id === $target->id;
    }

    public function delete(User $user, User $target): bool
    {
        if (!$user->isAdmin()) return false;
        if ($target->isAdmin()) return false;
        return true;
    }

    public function promote(User $user): bool
    {
        return $user->isAdmin();
    }

    public function demote(User $user): bool
    {
        return $user->isAdmin();
    }

    public function viewMembers(User $user, ?User $servant = null): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isServant() && $servant && $user->id === $servant->id) return true;
        return false;
    }

    public function viewServants(User $user): bool
    {
        return $user->isAdmin();
    }

    public function regenerateQrToken(User $user, User $target): bool
    {
        if ($user->isAdmin()) return true;
        return $user->id === $target->id;
    }
}
