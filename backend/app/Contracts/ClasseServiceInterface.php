<?php

namespace App\Contracts;

interface ClasseServiceInterface
{
    public function all(?string $search = null): array;
    public function findById(int $id): ?array;
    public function create(array $data): array;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function getDetail(int $id): array;
    public function assignServant(int $classeId, int $servantId): array;
    public function removeServant(int $classeId, int $servantId): array;
    public function updateOrder(array $orderedIds): bool;
    public function getMembers(int $classeId, int $perPage = 15): array;
    public function getServants(int $classeId, int $perPage = 15): array;
}
