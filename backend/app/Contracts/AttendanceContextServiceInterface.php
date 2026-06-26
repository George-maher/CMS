<?php

namespace App\Contracts;

interface AttendanceContextServiceInterface
{
    public function list(int $perPage = 15): array;
    public function listActive(): array;
    public function listActiveForChurch(int $churchId): array;
    public function findById(int $id): ?array;
    public function create(array $data, int $creatorId): array;
    public function update(int $id, array $data, ?int $updaterId = null): array;
    public function delete(int $id): void;
    public function getDefaultId(): ?int;
}
