<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class DailyVersePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin || $user->role === UserRole::Servant;
    }

    public function update(User $user): bool
    {
        return $user->role === UserRole::Admin || $user->role === UserRole::Servant;
    }

    public function delete(User $user): bool
    {
        return $user->role === UserRole::Admin || $user->role === UserRole::Servant;
    }

    public function activate(User $user): bool
    {
        return $user->role === UserRole::Admin || $user->role === UserRole::Servant;
    }
}
