<?php

namespace App\Contracts;

use App\Enums\UserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface UserRepositoryInterface
{
    public function findById(int $id);
    public function findByEmail(string $email);
    public function findByEmailByChurch(string $email);
    public function create(array $data);
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function paginateMembersByClassYear(int $classYearId, int $perPage = 15): LengthAwarePaginator;
    public function findServantsByAdmin(int $adminId): Collection;
    public function findMembersByServant(int $servantId): Collection;
    public function findMembersByClassYear(int $classYearId): Collection;
    public function countAdmins(): int;
    public function updateRole(int $id, string $role): bool;
}
