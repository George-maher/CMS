<?php

namespace App\Repositories;

use App\Contracts\PointRepositoryInterface;
use App\Models\Point;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PointRepository implements PointRepositoryInterface
{
    public function findById(int $id)
    {
        return Point::find($id);
    }

    public function create(array $data)
    {
        return Point::create($data);
    }

    public function delete(int $id): bool
    {
        $point = $this->findById($id);
        if (!$point) {
            return false;
        }
        return $point->delete();
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = Point::query()->with(['user']);

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->latest()->paginate($perPage);
    }

    public function getTotalPointsByUser(int $userId): int
    {
        return (int) Point::where('user_id', $userId)->sum('points');
    }
}
