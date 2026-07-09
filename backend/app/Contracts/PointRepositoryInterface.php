<?php

namespace App\Contracts;

use App\Models\Point;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PointRepositoryInterface
{
    public function findById(int $id): ?Point;

    /** @param array<string, mixed> $data */
    public function create(array $data): Point;

    public function delete(int $id): bool;

    /** @param array<string, mixed> $filters */
    /** @return LengthAwarePaginator<int, Point> */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    public function getTotalPointsByUser(int $userId): int;
}
