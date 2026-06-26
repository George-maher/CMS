<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PointRepositoryInterface
{
    public function findById(int $id);
    public function create(array $data);
    public function delete(int $id): bool;
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function getTotalPointsByUser(int $userId): int;
}
