<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    public function findByEmailByChurch(string $email): ?User;

    /** @param array<string, mixed> $data */
    public function create(array $data): User;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    /** @param array<string, mixed> $filters */
    /** @return LengthAwarePaginator<int, User> */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, User> */
    public function paginateMembersByClassYear(int $classYearId, int $perPage = 15): LengthAwarePaginator;

    /** @return Collection<int, User> */
    public function findMembersByServant(int $servantId): Collection;

    /** @return Collection<int, User> */
    public function findMembersByClassYear(int $classYearId): Collection;

    public function countAdmins(): int;

    public function updateRole(int $id, string $role): bool;

    /** @return Collection<int, User> */
    public function getServantsByChurch(int $churchId): Collection;

    /** @return Collection<int, User> */
    public function getMembersByServant(int $servantId): Collection;

    public function demoteFromAdmin(int $id, string $newRole = 'member'): bool;

    /** @param array<int, int> $ids @return \Illuminate\Support\Collection<int, \App\Models\User> */
    public function findByIds(array $ids): \Illuminate\Support\Collection;
}
