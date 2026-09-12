<?php

namespace App\Policies;

use App\Contracts\ScopeResolverInterface;
use App\Models\Stage;
use App\Models\User;

class StagePolicy
{
    public function __construct(
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return ! $user->isMember();
    }

    public function view(User $user, Stage $stage): bool
    {
        return $this->scopeResolver->canAccessStage($user, $stage);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Stage $stage): bool
    {
        return $user->isAdmin() && $stage->church_id === $user->church_id;
    }

    public function delete(User $user, Stage $stage): bool
    {
        return $user->isAdmin() && $stage->church_id === $user->church_id;
    }

    public function manageClasses(User $user, Stage $stage): bool
    {
        if ($user->isAdmin()) {
            return $stage->church_id === $user->church_id;
        }

        return $user->isStageAdmin() && $this->scopeResolver->canAccessStage($user, $stage);
    }
}
