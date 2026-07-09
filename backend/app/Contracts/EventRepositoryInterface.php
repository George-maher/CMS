<?php

namespace App\Contracts;

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EventRepositoryInterface
{
    public function findById(int $id): ?Event;

    /** @param array<string, mixed> $data */
    public function create(array $data): Event;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    /** @param array<string, mixed> $filters */
    /** @return LengthAwarePaginator<int, Event> */
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator;
}
