<?php

namespace App\Repositories;

use App\Contracts\ClasseRepositoryInterface;
use App\Enums\UserRole;
use App\Models\Classe;
use Illuminate\Database\Eloquent\Collection;

class ClasseRepository implements ClasseRepositoryInterface
{
    public function all(?string $search = null): Collection
    {
        $query = Classe::with(['stage'])
            ->withCount([
                'allUsers as member_count' => fn($q) => $q->where('role', UserRole::Member),
                'servants as servant_count',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('display_order')->get();
    }

    public function findById(int $id)
    {
        return Classe::with(['stage'])
            ->withCount([
                'allUsers as member_count' => fn($q) => $q->where('role', UserRole::Member),
                'servants as servant_count',
            ])
            ->find($id);
    }

    public function create(array $data)
    {
        return Classe::create($data);
    }

    public function update(int $id, array $data): bool
    {
        $classe = $this->findById($id);
        if (!$classe) return false;
        return $classe->update($data);
    }

    public function delete(int $id): bool
    {
        $classe = $this->findById($id);
        if (!$classe) return false;
        return $classe->delete();
    }

    public function findByStage(int $stageId, ?string $search = null): Collection
    {
        $query = Classe::where('stage_id', $stageId)
            ->withCount([
                'allUsers as member_count' => fn($q) => $q->where('role', UserRole::Member),
                'servants as servant_count',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('display_order')->get();
    }

    public function updateOrder(array $orderedIds): bool
    {
        foreach ($orderedIds as $index => $id) {
            Classe::where('id', $id)->update(['display_order' => $index + 1]);
        }
        return true;
    }
}
