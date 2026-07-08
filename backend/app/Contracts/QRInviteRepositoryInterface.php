<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface QRInviteRepositoryInterface
{
    public function findById(int $id): ?\App\Models\QRInvite;
    public function findByToken(string $token): ?\App\Models\QRInvite;
    public function create(array $data): \App\Models\QRInvite;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function revoke(int $id): bool;
}
