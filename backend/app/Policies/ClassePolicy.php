<?php

namespace App\Policies;

use App\Contracts\ScopeResolverInterface;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;

/**
 * Authorization rules for classes.
 *
 * Type-safety note (why there is no `create(User, ?Stage $stage = null)`)
 * -------------------------------------------------------------------------
 * A policy is invoked by Laravel with the *arguments* supplied to
 * `Gate::authorize()`. Because `Gate::resolveAuthCallback()` resolves the policy
 * from `getPolicyFor($arguments[0])`, the ability that actually runs depends on
 * the class of the first argument:
 *
 *   authorize('create', $stage)        -> StagePolicy::create()
 *   authorize('create', Classe::class)  -> ClassePolicy::create()
 *
 * A single `create()` method that accepted "either a Stage or nothing" could
 * therefore never be reached from both call sites, and if it *were* reached
 * with a class-name string it would raise a TypeError (a 500) instead of a clean
 * authorization denial.
 *
 * The two intents are now separate, explicitly named, and each has one shape:
 *
 *   create(User, string $model = '')  — generic model check; deny by default,
 *                                        because a class can only ever be
 *                                        created inside a specific stage.
 *   createInStage(User, Stage)         — the real rule, always with a Stage.
 */
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

    /**
     * Generic model-class authorization.
     *
     * Deny by default: without a resolved Stage there is nothing to authorize,
     * and allowing it would mean "the caller may create a class somewhere".
     */
    public function create(User $user, string $model = ''): bool
    {
        return false;
    }

    /**
     * Authorization to create a class inside a specific stage.
     *
     * A Church Admin may create classes anywhere in their own church. A Stage
     * Admin may create classes only inside the stage they are assigned to. No
     * other role may create classes.
     */
    public function createInStage(User $user, Stage $stage): bool
    {
        if ($user->isPlatformAdmin()) {
            return false;
        }

        if ($user->isAdmin()) {
            return $stage->church_id === $user->church_id;
        }

        return $user->isStageAdmin()
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
        if ($user->isPlatformAdmin()) {
            return false;
        }

        if ($user->isAdmin()) {
            return $classe->church_id === $user->church_id;
        }

        return $user->isStageAdmin() && $this->scopeResolver->canAccessClass($user, $classe);
    }
}
