<?php

namespace App\Contracts;

interface MembershipRequestRepositoryInterface
{
    public function create(array $data): \App\Models\MembershipRequest;
    public function findById(int $id): ?\App\Models\MembershipRequest;
    public function paginate(int $perPage = 15, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator;
    public function update(int $id, array $data): bool;
    public function findByEmailChurch(string $email, int $churchId): ?\App\Models\MembershipRequest;
}
