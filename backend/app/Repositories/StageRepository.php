<?php

namespace App\Repositories;

use App\Contracts\StageRepositoryInterface;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Collection;

class StageRepository implements StageRepositoryInterface
{
    public function all(?string $search = null): Collection
    {
        $query = Stage::withCount(['classes']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('display_order')->get();
    }

    public function structure(?string $search = null): Collection
    {
        $query = Stage::with(['classes' => function ($q) {
            $q->orderBy('display_order');
        }])->withCount(['classes']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhereHas('classes', fn($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        return $query->orderBy('display_order')->get();
    }

    public function findById(int $id)
    {
        return Stage::withCount(['classes'])->find($id);
    }

    public function create(array $data)
    {
        return Stage::create($data);
    }

    public function update(int $id, array $data): bool
    {
        $stage = $this->findById($id);
        if (!$stage) return false;
        return $stage->update($data);
    }

    public function delete(int $id): bool
    {
        $stage = $this->findById($id);
        if (!$stage) return false;
        return $stage->delete();
    }

    public function count(): int
    {
        return Stage::count();
    }
}
