<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EventRepositoryInterface
{
    public function findById(int $id): ?\App\Models\Event;

    /** @param array<string, mixed> $data */
    public function create(array $data): \App\Models\Event;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    /** @param array<string, mixed> $filters */
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Event> */
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator;
}
