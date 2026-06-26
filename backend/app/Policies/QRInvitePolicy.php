<?php

namespace App\Policies;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\QRInvite;
use App\Models\User;

class QRInvitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isServant();
    }

    public function view(User $user, QRInvite $invite): bool
    {
        if ($user->isAdmin()) return true;
        return $user->id === $invite->created_by;
    }

    public function create(User $user, ?string $type = null): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isServant() && $type === QRInviteType::MemberInvite->value) return true;
        return false;
    }

    public function revoke(User $user, QRInvite $invite): bool
    {
        if ($user->isAdmin()) return true;
        return $user->id === $invite->created_by;
    }

    public function delete(User $user, QRInvite $invite): bool
    {
        return $user->isAdmin();
    }
}
