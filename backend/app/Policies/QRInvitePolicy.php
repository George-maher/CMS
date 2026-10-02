<?php

namespace App\Policies;

use App\Enums\QRInviteType;
use App\Models\QRInvite;
use App\Models\User;

class QRInvitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isServant() || $user->isStageAdmin();
    }

    public function view(User $user, QRInvite $invite): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isStageAdmin() && $invite->stage_id !== null && $invite->stage_id === $user->stage_id) {
            return true;
        }

        return $user->id === $invite->created_by;
    }

    public function create(User $user, ?string $type = null): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->isStageAdmin()) {
            return true;
        }
        if ($user->isServant() && $type === QRInviteType::ServantToMemberInvite->value) {
            return true;
        }

        return false;
    }

    public function revoke(User $user, QRInvite $invite): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isStageAdmin() && $invite->stage_id !== null && $invite->stage_id === $user->stage_id) {
            return true;
        }

        return $user->id === $invite->created_by;
    }

    public function delete(User $user, QRInvite $invite): bool
    {
        return $user->isAdmin();
    }
}
