<?php

namespace App\Policies;

use App\Contracts\ScopeResolverInterface;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;

class ClassePolicy
{
    public function __construct(
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return ! $user->isMember();
    }

    public function view(User $user, Classe $classe): bool
    {
        return $this->scopeResolver->canAccessClass($user, $classe);
    }

    public function create(User $user, ?Stage $stage = null): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isStageAdmin()
            && $stage !== null
            && $stage->church_id === $user->church_id
            && $this->scopeResolver->canAccessStage($user, $stage);
    }

    public function update(User $user, Classe $classe): bool
    {
        return $this->canManage($user, $classe);
    }

    public function delete(User $user, Classe $classe): bool
    {
        return $this->canManage($user, $classe);
    }

    public function manageServants(User $user, Classe $classe): bool
    {
        return $this->canManage($user, $classe);
    }

    public function manageMembers(User $user, Classe $classe): bool
    {
        return $this->canManage($user, $classe);
    }

    public function reorder(User $user): bool
    {
        return $user->isAdmin() || $user->isStageAdmin();
    }

    private function canManage(User $user, Classe $classe): bool
    {
        if ($user->isAdmin()) {
            return $classe->church_id === $user->church_id;
        }

        return $user->isStageAdmin() && $this->scopeResolver->canAccessClass($user, $classe);
    }
}
