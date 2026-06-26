<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Church;
use App\Models\User;

class ChurchDeletionPolicy
{
    public function viewSummary(User $user, Church $church): bool
    {
        return $user->role === UserRole::PlatformAdmin;
    }

    public function softDelete(User $user, Church $church): bool
    {
        return $user->role === UserRole::PlatformAdmin;
    }

    public function hardDelete(User $user, Church $church): bool
    {
        return $user->role === UserRole::PlatformAdmin;
    }

    public function restore(User $user, Church $church): bool
    {
        return $user->role === UserRole::PlatformAdmin;
    }

    public function listChurches(User $user): bool
    {
        return $user->role === UserRole::PlatformAdmin;
    }
}
