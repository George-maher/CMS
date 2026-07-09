<?php

namespace App\Contracts;

interface ClasseServiceInterface
{
    /** @return array<string, mixed> */
    public function all(?string $search = null): array;

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function create(array $data): array;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    /** @return array<string, mixed> */
    public function getDetail(int $id): array;

    /** @return array<string, mixed> */
    public function assignServant(int $classeId, int $servantId): array;

    /** @return array<string, mixed> */
    public function removeServant(int $classeId, int $servantId): array;

    /** @param array<int, int> $orderedIds */
    public function updateOrder(array $orderedIds): bool;

    /** @return array<string, mixed> */
    public function getMembers(int $classeId, int $perPage = 15): array;

    /** @return array<string, mixed> */
    public function getServants(int $classeId, int $perPage = 15): array;
}
