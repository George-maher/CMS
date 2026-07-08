<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EventRepositoryInterface
{
    public function findById(int $id): ?\App\Models\Event;
    public function create(array $data): \App\Models\Event;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator;
}
