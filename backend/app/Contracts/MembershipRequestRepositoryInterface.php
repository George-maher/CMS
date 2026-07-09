<?php

namespace App\Contracts;

use App\Models\MembershipRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MembershipRequestRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): MembershipRequest;

    public function findById(int $id): ?MembershipRequest;

    /** @param array<string, mixed> $filters */
    /** @return LengthAwarePaginator<int, MembershipRequest> */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function findByEmailChurch(string $email, int $churchId): ?MembershipRequest;
}
