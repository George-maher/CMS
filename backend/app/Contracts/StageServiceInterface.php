<?php

namespace App\Contracts;

interface StageServiceInterface
{
    public function all(?string $search = null): array;
    public function structure(?string $search = null): array;
    public function findById(int $id): ?array;
    public function create(array $data): array;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function createBulk(int $churchId, int $count): array;
    public function getClasses(int $stageId, ?string $search = null): array;
}
