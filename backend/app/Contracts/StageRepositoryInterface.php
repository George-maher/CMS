<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface StageRepositoryInterface
{
    public function all(?string $search = null): Collection;
    public function structure(?string $search = null): Collection;
    public function findById(int $id);
    public function create(array $data);
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function count(): int;
}
