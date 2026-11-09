<?php

namespace App\Repositories;

use App\Contracts\ClasseRepositoryInterface;
use App\Enums\UserRole;
use App\Models\Classe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ClasseRepository implements ClasseRepositoryInterface
{
    /** @return Collection<int, Classe> */
    public function all(?string $search = null): Collection
    {
        /** @var Builder<Classe> $query */
        $query = Classe::with(['stage', 'secretary'])
            ->withCount([
                'allUsers as member_count' => fn (Builder $q) => $q->where('role', UserRole::Member),
                'servants as servant_count',
            ]);

        if ($search) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('display_order')->get();
    }

    public function findById(int $id): ?Classe
    {
        /** @var Builder<Classe> $query */
        $query = Classe::with(['stage', 'secretary'])
            ->withCount([
                'allUsers as member_count' => fn (Builder $q) => $q->where('role', UserRole::Member),
                'servants as servant_count',
            ]);

        return $query->find($id);
    }

    /**
     * Load a class and lock its row for the duration of the surrounding
     * transaction.
     *
     * Secretary assignment is a read-modify-write on a single column: without
     * the lock two concurrent requests could each read "no secretary", each
     * validate against that snapshot and both write, so the last writer wins
     * silently. The lock serializes the decision. SQLite (used by the test
     * suite) ignores FOR UPDATE, but the single `class_secretary_id` column
     * still makes a duplicate secretary unrepresentable.
     */
    public function findByIdForUpdate(int $id): ?Classe
    {
        /** @var Builder<Classe> $query */
        $query = Classe::with(['stage', 'secretary'])->lockForUpdate();

        return $query->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Classe
    {
        return Classe::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): bool
    {
        $classe = $this->findById($id);
        if (! $classe) {
            return false;
        }

        return $classe->update($data);
    }

    public function delete(int $id): bool
    {
        $classe = $this->findById($id);
        if (! $classe) {
            return false;
        }

        return (bool) $classe->delete();
    }

    /** @return Collection<int, Classe> */
    public function findByStage(int $stageId, ?string $search = null): Collection
    {
        /** @var Builder<Classe> $query */
        $query = Classe::where('stage_id', $stageId)
            ->with(['secretary'])
            ->withCount([
                'allUsers as member_count' => fn (Builder $q) => $q->where('role', UserRole::Member),
                'servants as servant_count',
            ]);

        if ($search) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('display_order')->get();
    }

    /**
     * @param  array<int, int>  $orderedIds
     */
    public function updateOrder(array $orderedIds): bool
    {
        foreach ($orderedIds as $index => $id) {
            Classe::where('id', $id)->update(['display_order' => $index + 1]);
        }

        return true;
    }
}
