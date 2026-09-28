<?php

namespace App\Contracts;

interface MembershipRequestRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): \App\Models\MembershipRequest;

    public function findById(int $id): ?\App\Models\MembershipRequest;

    /** @param array<string, mixed> $filters */
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\MembershipRequest> */
    public function paginate(int $perPage = 15, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;
    public function findByEmailChurch(string $email, int $churchId): ?\App\Models\MembershipRequest;
}
