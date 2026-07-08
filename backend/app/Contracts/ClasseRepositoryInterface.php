<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface ClasseRepositoryInterface
{
    /** @return Collection<int, \App\Models\Classe> */
    public function all(?string $search = null): Collection;
    public function findById(int $id): ?\App\Models\Classe;
    public function create(array $data): \App\Models\Classe;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    /** @return Collection<int, \App\Models\Classe> */
    public function findByStage(int $stageId, ?string $search = null): Collection;
    public function updateOrder(array $orderedIds): bool;
}
