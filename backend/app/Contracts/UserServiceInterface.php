<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UserServiceInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: LengthAwarePaginator<int, User>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function listUsers(int $perPage = 15, array $filters = []): array;

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function create(array $data, ?int $authUserId = null): array;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed>|null */
    public function update(int $id, array $data): ?array;

    public function delete(int $id): bool;

    /** @return array<string, mixed> */
    public function servants(int $churchId): array;

    /** @return array<string, mixed> */
    public function getMembers(int $servantId, ?int $classYearId = null): array;

    /** @return array<string, mixed> */
    public function promote(int $userId, int $authUserId, string $newRole): array;

    /** @return array<string, mixed> */
    public function demoteFromAdmin(int $userId, int $authUserId): array;

    /** @return array<string, mixed> */
    public function getAttendanceHistory(int $userId, int $perPage = 15): array;

    /** @return array<string, mixed> */
    public function getAvailablePermissions(int $userId): array;

    /** @param array<int, string> $permissions */
    /** @return array<string, mixed> */
    public function updatePermissions(int $userId, array $permissions, int $authUserId): array;

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, string>  $permissions
     * @return array<string, mixed>
     */
    public function bulkUpdatePermissions(array $userIds, array $permissions, int $authUserId): array;

    /** @return array<string, mixed> */
    public function regenerateAttendanceToken(int $userId): array;
}
