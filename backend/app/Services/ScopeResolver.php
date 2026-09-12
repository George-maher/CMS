<?php

namespace App\Services;

use App\Contracts\ScopeResolverInterface;
use App\Enums\UserScope;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;

class ScopeResolver implements ScopeResolverInterface
{
    public function getScope(User $user): UserScope
    {
        return $user->getScope();
    }

    /**
     * @return array<int, int>
     */
    public function allowedStageIds(User $user): array
    {
        $scope = $this->getScope($user);

        return match ($scope) {
            UserScope::Church => $this->stageIdsForQuery(),
            UserScope::Stage => $user->stage_id ? [(int) $user->stage_id] : [],
            UserScope::ClassScope => $this->stageIdsOfClasses($this->allowedClassIds($user) ?? []),
            UserScope::Self => $this->stageIdsOfClasses($user->class_id ? [(int) $user->class_id] : []),
        };
    }

    /**
     * @return array<int, int>|null
     */
    public function allowedClassIds(User $user): ?array
    {
        $scope = $this->getScope($user);

        return match ($scope) {
            UserScope::Church => null,
            UserScope::Stage => $user->stage_id
                ? $this->classIdsForStage((int) $user->stage_id)
                : [],
            UserScope::ClassScope => $user->getServantClassIds() ?? [],
            UserScope::Self => $user->class_id ? [(int) $user->class_id] : [],
        };
    }

    public function canAccessStage(User $user, Stage $stage): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if ($stage->church_id !== $user->church_id) {
            return false;
        }

        return in_array((int) $stage->id, $this->allowedStageIds($user), true);
    }

    public function canAccessClass(User $user, Classe $classe): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        $allowed = $this->allowedClassIds($user);

        if ($allowed === null) {
            return $classe->church_id === $user->church_id;
        }

        return in_array((int) $classe->id, $allowed, true);
    }

    public function canAccessUser(User $actor, User $target): bool
    {
        if ($actor->isPlatformAdmin()) {
            return true;
        }

        if ($actor->church_id === null || $actor->church_id !== $target->church_id) {
            return false;
        }

        $scope = $this->getScope($actor);

        return match ($scope) {
            UserScope::Church => true,
            UserScope::Stage => in_array($this->userStageId($target) ?? -1, $this->allowedStageIds($actor), true),
            UserScope::ClassScope => $target->class_id !== null
                && in_array((int) $target->class_id, $this->allowedClassIds($actor) ?? [], true),
            UserScope::Self => $actor->id === $target->id,
        };
    }

    public function userStageId(User $user): ?int
    {
        if ($user->stage_id) {
            return (int) $user->stage_id;
        }

        if ($user->class_id) {
            $stageId = $user->classe()->value('stage_id');

            return $stageId !== null && is_numeric($stageId) ? (int) $stageId : null;
        }

        return null;
    }

    public function userClassId(User $user): ?int
    {
        return $user->class_id !== null ? (int) $user->class_id : null;
    }

    /**
     * Stage ids in the current church scope (global ChurchScope applies).
     *
     * @return array<int, int>
     */
    private function stageIdsForQuery(): array
    {
        $ids = Stage::query()->pluck('id')->toArray();

        /** @var array<int, int> $result */
        $result = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $result[] = (int) $id;
            }
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private function classIdsForStage(int $stageId): array
    {
        $ids = Classe::query()
            ->where('stage_id', $stageId)
            ->pluck('id')
            ->toArray();

        /** @var array<int, int> $result */
        $result = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $result[] = (int) $id;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $classIds
     * @return array<int, int>
     */
    private function stageIdsOfClasses(array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }

        $ids = Classe::query()
            ->whereIn('id', $classIds)
            ->pluck('stage_id')
            ->toArray();

        /** @var array<int, int> $result */
        $result = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $result[] = (int) $id;
            }
        }

        return array_values(array_unique($result));
    }
}
