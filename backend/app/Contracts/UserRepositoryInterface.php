<?php

namespace App\Contracts;

use App\Enums\UserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface UserRepositoryInterface
{
    public function findById(int $id): ?\App\Models\User;
    public function findByEmail(string $email): ?\App\Models\User;
    public function findByEmailByChurch(string $email): ?\App\Models\User;
    /** @param array<string, mixed> $data */
    public function create(array $data): \App\Models\User;
    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    /** @param array<string, mixed> $filters */
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\User> */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\User> */
    public function paginateMembersByClassYear(int $classYearId, int $perPage = 15): LengthAwarePaginator;
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> */
    public function findServantsByAdmin(int $adminId): Collection;
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> */
    public function findMembersByServant(int $servantId): Collection;
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> */
    public function findMembersByClassYear(int $classYearId): Collection;
    public function countAdmins(): int;
    /** @param string $role */
    public function updateRole(int $id, string $role): bool;
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> */
    public function getServantsByChurch(int $churchId): Collection;
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> */
    public function getMembersByServant(int $servantId): Collection;
    public function demoteFromAdmin(int $id): bool;
    /** @param array<int, int> $ids @return \Illuminate\Support\Collection<int, \App\Models\User> */
    public function findByIds(array $ids): \Illuminate\Support\Collection;
}
