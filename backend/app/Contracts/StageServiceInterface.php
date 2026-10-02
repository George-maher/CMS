<?php

namespace App\Contracts;

interface StageServiceInterface
{
    /** @return array<string, mixed> */
    public function all(?string $search = null): array;

    /** @return array<string, mixed> */
    public function structure(?string $search = null): array;

    /** @return array<int, array<string, mixed>> */
    public function stagesWithClasses(?string $search = null): array;

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function create(array $data): array;

    /** @return array<string, mixed> */
    public function createBulk(int $churchId, int $count): array;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    /** @return array<string, mixed> */
    public function getClasses(int $stageId, ?string $search = null): array;
}
