<?php

namespace App\Contracts;

interface MembershipRequestServiceInterface
{
    public function submit(array $data, int $churchId): array;
    public function approve(int $id, int $adminId): array;
    public function reject(int $id, int $adminId, string $reason): array;
    public function listRequests(int $churchId, int $perPage = 15, array $filters = []): array;
    public function findById(int $id, int $churchId): ?\App\Models\MembershipRequest;
}
