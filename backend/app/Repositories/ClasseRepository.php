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
        $query = Classe::with(['stage'])
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
        $query = Classe::with(['stage'])
            ->withCount([
                'allUsers as member_count' => fn (Builder $q) => $q->where('role', UserRole::Member),
                'servants as servant_count',
            ]);

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
