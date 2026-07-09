<?php

namespace App\Contracts;

use App\Models\QRInvite;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface QRInviteRepositoryInterface
{
    public function findById(int $id): ?QRInvite;

    public function findByToken(string $token): ?QRInvite;

    /** @param array<string, mixed> $data */
    public function create(array $data): QRInvite;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    /** @param array<string, mixed> $filters */
    /** @return LengthAwarePaginator<int, QRInvite> */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    public function revoke(int $id): bool;
}
