<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AttendanceContextRepositoryInterface
{
    public function findById(int $id);
    public function findBySlug(string $slug);
    public function create(array $data);
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator;
    public function getActive();
    public function getDefault();
    public function clearDefault(): int;
    public function getActiveForChurch(int $churchId);
}
