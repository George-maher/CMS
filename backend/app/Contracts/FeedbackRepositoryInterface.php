<?php

namespace App\Contracts;

use App\Models\Feedback;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface FeedbackRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Feedback;

    /** @param array<string, mixed> $filters */
    /** @return LengthAwarePaginator<int, Feedback> */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    public function findById(int $id): ?Feedback;

    public function markAsResolved(int $id): bool;

    public function countUnresolved(array|int|null $classYearIds = null): int;
}
