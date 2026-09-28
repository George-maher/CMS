<?php

namespace App\Contracts;

use App\Models\MembershipRequest;

interface MembershipRequestServiceInterface
{
    /**
     * Accept a public join request without revealing account existence.
     *
     * Never throws for "this address already exists" reasons: such submissions
     * are suppressed internally and acknowledged identically.
     *
     * @param  array<string, mixed>  $data
     * @return array{accepted: bool, message: string}
     */
    public function submit(array $data, int $churchId): array;

    /** @return array<string, mixed> */
    public function approve(int $id, int $adminId): array;

    /** @return array<string, mixed> */
    public function reject(int $id, int $adminId, string $reason): array;

    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    public function listRequests(int $churchId, int $perPage = 15, array $filters = []): array;

    public function findById(int $id, int $churchId): ?MembershipRequest;
}
