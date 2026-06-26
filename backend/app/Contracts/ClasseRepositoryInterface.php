<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface ClasseRepositoryInterface
{
    public function all(?string $search = null): Collection;
    public function findById(int $id);
    public function create(array $data);
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function findByStage(int $stageId, ?string $search = null): Collection;
    public function updateOrder(array $orderedIds): bool;
}
