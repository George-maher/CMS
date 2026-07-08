<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface StageRepositoryInterface
{
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Stage> */
    public function all(?string $search = null): Collection;
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Stage> */
    public function structure(?string $search = null): Collection;
    public function findById(int $id): ?\App\Models\Stage;
    public function create(array $data): \App\Models\Stage;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function count(): int;
}
