<?php

namespace App\Contracts;

use App\Models\PasswordResetRequest;

interface PasswordResetRequestServiceInterface
{
    /** @param array<string, mixed> $data */
    /** @return array{message: string} */
    public function submitRequest(array $data): array;

    /** @return array<string, mixed> */
    public function approve(int $id, int $adminId): array;

    /** @return array<string, mixed> */
    public function reject(int $id, int $adminId, string $reason): array;

    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    public function listRequests(int $churchId, int $perPage = 15, array $filters = []): array;

    public function findById(int $id, int $churchId): ?PasswordResetRequest;

    /** @return array<string, mixed> */
    public function completeReset(string $token, string $password): array;
}
