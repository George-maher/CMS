<?php

namespace App\Contracts;

use App\Enums\QRInviteType;
use App\Enums\UserRole;

interface QRInviteServiceInterface
{
    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function createInvite(array $data, int $creatorId): array;

    /** @return array<string, mixed> */
    public function validateToken(string $token): array;
    /** @return array<string, mixed> */
    public function validateTokenForRegistration(string $token): array;
    /** @return array<string, mixed> */
    public function getInviteDetails(string $token): array;
    /** @return array<string, mixed> */
    public function acceptInvite(string $token, int $userId, ?int $classId = null): array;
    public function findById(int $id): ?\App\Models\QRInvite;
    public function revokeInvite(int $id): bool;
    public function getInviteUrl(string $token): string;

    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    public function listInvites(int $perPage = 15, array $filters = []): array;
}
