<?php

namespace App\Contracts;

interface AttendanceContextServiceInterface
{
    /** @return array{data: \Illuminate\Contracts\Pagination\LengthAwarePaginator, meta: array<string, mixed>} */
    public function list(int $perPage = 15): array;
    /** @return array<string, mixed> */
    public function listActive(): array;
    /** @return array<string, mixed> */
    public function listActiveForChurch(int $churchId): array;
    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function create(array $data, int $creatorId): array;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function update(int $id, array $data, ?int $updaterId = null): array;

    public function delete(int $id): void;
    public function getDefaultId(): ?int;
}
