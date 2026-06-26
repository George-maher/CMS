<?php

namespace App\Contracts;

use App\Enums\QRInviteType;
use App\Enums\UserRole;

interface QRInviteServiceInterface
{
    public function createInvite(array $data, int $creatorId): array;
    public function findById(int $id);
    public function validateToken(string $token): array;
    public function validateTokenForRegistration(string $token): array;
    public function getInviteDetails(string $token): array;
    public function acceptInvite(string $token, int $userId, ?int $classId = null): array;
    public function revokeInvite(int $id): bool;
    public function getInviteUrl(string $token): string;
    public function listInvites(int $perPage = 15, array $filters = []): array;
}
