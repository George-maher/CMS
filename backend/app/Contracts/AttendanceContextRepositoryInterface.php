<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AttendanceContextRepositoryInterface
{
    public function findById(int $id): ?\App\Models\AttendanceContext;
    public function findBySlug(string $slug): ?\App\Models\AttendanceContext;

    /** @param array<string, mixed> $data */
    public function create(array $data): \App\Models\AttendanceContext;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    /** @param array<string, mixed> $filters */
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\AttendanceContext> */
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator;
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\AttendanceContext> */
    public function getActive(): \Illuminate\Database\Eloquent\Collection;
    public function getDefault(): ?\App\Models\AttendanceContext;
    public function clearDefault(): int;
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\AttendanceContext> */
    public function getActiveForChurch(int $churchId): \Illuminate\Database\Eloquent\Collection;
}
