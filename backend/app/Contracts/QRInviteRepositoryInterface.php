<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface QRInviteRepositoryInterface
{
    public function findById(int $id): ?\App\Models\QRInvite;
    public function findByToken(string $token): ?\App\Models\QRInvite;
    /** @param array<string, mixed> $data */
    public function create(array $data): \App\Models\QRInvite;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    /** @param array<string, mixed> $filters */
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\QRInvite> */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function revoke(int $id): bool;
}
