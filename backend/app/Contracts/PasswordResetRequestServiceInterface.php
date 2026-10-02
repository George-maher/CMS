<?php

namespace App\Contracts;

use App\Models\PasswordResetRequest;

interface PasswordResetRequestServiceInterface
{
    /** @param array<string, mixed> $data */
    /** @return array{message: string} */
    public function submitRequest(array $data): array;

    /** @return array{message: string, request: PasswordResetRequest} */
    public function approve(int $id, int $adminId): array;

    /** @return array{message: string, request: PasswordResetRequest} */
    public function reject(int $id, int $adminId, string $reason): array;

    /**
     * Set a brand-new password for the requester of an approved request.
     *
     * @return array{message: string}
     */
    public function resetPassword(int $id, int $adminId, string $password): array;

    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    public function listRequests(int $churchId, int $perPage = 15, array $filters = []): array;

    public function findById(int $id, int $churchId): ?PasswordResetRequest;
}
