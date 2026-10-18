<?php

namespace App\Policies;

use App\Contracts\ScopeResolverInterface;
use App\Enums\QRInviteType;
use App\Models\QRInvite;
use App\Models\User;

class QRInvitePolicy
{
    public function __construct(
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->isAdmin()
            || $user->isServant()
            || ($user->isStageAdmin() && $this->scopeResolver->userStageId($user) !== null);
    }

    public function view(User $user, QRInvite $invite): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isStageAdmin()) {
            return $this->stageScopedInviteMatches($user, $invite);
        }

        return $user->id === $invite->created_by;
    }

    public function create(User $user, ?string $type = null): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->isStageAdmin()) {
            return $this->scopeResolver->userStageId($user) !== null;
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

        if ($user->isStageAdmin()) {
            return $this->stageScopedInviteMatches($user, $invite);
        }

        return $user->id === $invite->created_by;
    }

    public function delete(User $user, QRInvite $invite): bool
    {
        return $user->isAdmin();
    }

    private function stageScopedInviteMatches(User $user, QRInvite $invite): bool
    {
        $stageId = $this->scopeResolver->userStageId($user);

        return $stageId !== null
            && $invite->church_id !== null
            && (int) $invite->church_id === (int) $user->church_id
            && $invite->stage_id !== null
            && (int) $invite->stage_id === $stageId;
    }
}
