<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AttendanceContextPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isServant();
    }

    public function view(User $user): bool
    {
        return $user->isAdmin() || $user->isServant();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isServant();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin() || $user->isServant();
    }

    public function delete(User $user): bool
    {
        return $user->isAdmin() || $user->isAssistantAdmin();
    }

    public function toggleActive(User $user): bool
    {
        return $user->isAdmin() || $user->isAssistantAdmin();
    }

    public function restore(User $user): bool
    {
        return $user->isAdmin() || $user->isAssistantAdmin() || $user->isServant();
    }
}
