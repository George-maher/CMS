<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface FeedbackRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): \App\Models\Feedback;

    /** @param array<string, mixed> $filters */
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Feedback> */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function findById(int $id): ?\App\Models\Feedback;
    public function markAsResolved(int $id): bool;
    public function countUnresolved(array|int|null $classYearIds = null): int;
}
