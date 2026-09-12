<?php

namespace App\Contracts;

use App\Enums\UserScope;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;

interface ScopeResolverInterface
{
    /**
     * Effective organizational scope of a user ("where" they operate).
     */
    public function getScope(User $user): UserScope;

    /**
     * Stage ids the user may access/manage. Empty for fully restricted users.
     *
     * @return array<int, int>
     */
    public function allowedStageIds(User $user): array;

    /**
     * Class ids the user may access/manage. Null means church-wide (all classes).
     *
     * @return array<int, int>|null
     */
    public function allowedClassIds(User $user): ?array;

    public function canAccessStage(User $user, Stage $stage): bool;

    public function canAccessClass(User $user, Classe $classe): bool;

    public function canAccessUser(User $actor, User $target): bool;

    /**
     * Stage a user effectively belongs to (explicit stage_id or class→stage).
     */
    public function userStageId(User $user): ?int;

    /**
     * Class a user effectively belongs to (for members and servants).
     */
    public function userClassId(User $user): ?int;
}
