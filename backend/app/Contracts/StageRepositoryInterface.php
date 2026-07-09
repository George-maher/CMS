<?php

namespace App\Contracts;

use App\Models\Stage;
use Illuminate\Database\Eloquent\Collection;

interface StageRepositoryInterface
{
    /** @return Collection<int, Stage> */
    public function all(?string $search = null): Collection;

    /** @return Collection<int, Stage> */
    public function structure(?string $search = null): Collection;

    public function findById(int $id): ?Stage;

    /** @param array<string, mixed> $data */
    public function create(array $data): Stage;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    public function count(): int;
}
