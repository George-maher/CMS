<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface VerseRepositoryInterface
{
    public function findById(int $id);
    public function create(array $data);
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator;
    public function getActive();
    public function deactivateAll(): int;
}
