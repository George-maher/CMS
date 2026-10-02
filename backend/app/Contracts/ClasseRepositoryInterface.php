<?php

namespace App\Contracts;

use App\Models\Classe;
use Illuminate\Database\Eloquent\Collection;

interface ClasseRepositoryInterface
{
    /** @return Collection<int, Classe> */
    public function all(?string $search = null): Collection;

    public function findById(int $id): ?Classe;

    /** @param array<string, mixed> $data */
    public function create(array $data): Classe;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    /** @return Collection<int, Classe> */
    public function findByStage(int $stageId, ?string $search = null): Collection;

    /** @param array<int, int> $orderedIds */
    public function updateOrder(array $orderedIds): bool;
}
