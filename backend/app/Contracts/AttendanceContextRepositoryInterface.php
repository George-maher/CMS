<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AttendanceContextRepositoryInterface
{
    public function findById(int $id): ?\App\Models\AttendanceContext;
    public function findBySlug(string $slug): ?\App\Models\AttendanceContext;
    public function create(array $data): \App\Models\AttendanceContext;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator;
    public function getActive(): \Illuminate\Database\Eloquent\Collection;
    public function getDefault(): ?\App\Models\AttendanceContext;
    public function clearDefault(): int;
    public function getActiveForChurch(int $churchId): \Illuminate\Database\Eloquent\Collection;
}
