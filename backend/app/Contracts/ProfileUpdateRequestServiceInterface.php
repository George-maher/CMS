<?php

namespace App\Contracts;

use App\Models\ProfileUpdateRequest;

interface ProfileUpdateRequestServiceInterface
{
    /**
     * Member submits a profile update request.
     *
     * @param  array<string, mixed>  $data
     * @return array{message: string, request: ProfileUpdateRequest}
     */
    public function submitRequest(int $userId, array $data): array;

    /**
     * Responsible servant approves the request and applies changes.
     *
     * @return array{message: string, request: ProfileUpdateRequest}
     */
    public function approve(int $id, int $reviewerId): array;

    /**
     * Responsible servant rejects the request.
     *
     * @return array{message: string, request: ProfileUpdateRequest}
     */
    public function reject(int $id, int $reviewerId, string $reason): array;

    /**
     * List requests for a servant's assigned members.
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: list<ProfileUpdateRequest>, meta: array<string, mixed>}
     */
    public function listRequestsForServant(int $servantId, int $perPage = 15, array $filters = []): array;

    /**
     * List requests for admin (all members in church).
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: list<ProfileUpdateRequest>, meta: array<string, mixed>}
     */
    public function listRequestsForAdmin(int $churchId, int $perPage = 15, array $filters = []): array;

    /**
     * Get a request by ID (scoped to church for admin, or by reviewer for servant).
     */
    public function findById(int $id, int $churchId): ?ProfileUpdateRequest;

    /**
     * Get the pending request for a user, if any.
     */
    public function findPendingForUser(int $userId): ?ProfileUpdateRequest;

    /**
     * List all requests for a specific member (their own).
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: list<ProfileUpdateRequest>, meta: array<string, mixed>}
     */
    public function listRequestsForMember(int $userId, int $perPage = 15, array $filters = []): array;
}
