<?php

namespace App\Repositories;

use App\Contracts\StageRepositoryInterface;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageRepository implements StageRepositoryInterface
{
    /** @return Collection<int, Stage> */
    public function all(?string $search = null): Collection
    {
        /** @var Builder<Stage> $query */
        $query = Stage::withCount(['classes']);

        if ($search) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('display_order')->get();
    }

    /** @return Collection<int, Stage> */
    public function structure(?string $search = null): Collection
    {
        /** @var Builder<Stage> $query */
        $query = Stage::with(['classes' => function (HasMany $q) {
            $q->orderBy('display_order');
        }])->withCount(['classes']);

        if ($search) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('classes', fn (Builder $cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        return $query->orderBy('display_order')->get();
    }

    public function findById(int $id): ?Stage
    {
        return Stage::withCount(['classes'])->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Stage
    {
        return Stage::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): bool
    {
        $stage = $this->findById($id);
        if (! $stage) {
            return false;
        }

        return $stage->update($data);
    }

    public function delete(int $id): bool
    {
        $stage = $this->findById($id);
        if (! $stage) {
            return false;
        }

        return (bool) $stage->delete();
    }

    public function count(): int
    {
        return Stage::count();
    }
}
