<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PointRepositoryInterface
{
    public function findById(int $id): ?\App\Models\Point;

    /** @param array<string, mixed> $data */
    public function create(array $data): \App\Models\Point;

    public function delete(int $id): bool;

    /** @param array<string, mixed> $filters */
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Point> */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function getTotalPointsByUser(int $userId): int;
}
