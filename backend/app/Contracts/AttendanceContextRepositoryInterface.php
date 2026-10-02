<?php

namespace App\Contracts;

use App\Models\AttendanceContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface AttendanceContextRepositoryInterface
{
    public function findById(int $id): ?AttendanceContext;

    public function findBySlug(string $slug): ?AttendanceContext;

    /** @param array<string, mixed> $data */
    public function create(array $data): AttendanceContext;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    /** @param array<string, mixed> $filters */
    /** @return LengthAwarePaginator<int, AttendanceContext> */
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator;

    /** @return Collection<int, AttendanceContext> */
    public function getActive(): Collection;

    public function getDefault(): ?AttendanceContext;

    public function clearDefault(): int;

    /** @return Collection<int, AttendanceContext> */
    public function getActiveForChurch(int $churchId): Collection;
}
