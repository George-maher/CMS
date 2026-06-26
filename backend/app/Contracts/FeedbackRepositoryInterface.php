<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface FeedbackRepositoryInterface
{
    public function create(array $data);
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function findById(int $id);
    public function markAsResolved(int $id): bool;
    public function countUnresolved(array|int|null $classYearIds = null): int;
}
