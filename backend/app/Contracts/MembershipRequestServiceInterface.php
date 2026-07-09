<?php

namespace App\Contracts;

interface MembershipRequestServiceInterface
{
    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function submit(array $data, int $churchId): array;

    /** @return array<string, mixed> */
    public function approve(int $id, int $adminId): array;
    /** @return array<string, mixed> */
    public function reject(int $id, int $adminId, string $reason): array;

    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    public function listRequests(int $churchId, int $perPage = 15, array $filters = []): array;
    public function findById(int $id, int $churchId): ?\App\Models\MembershipRequest;
}
