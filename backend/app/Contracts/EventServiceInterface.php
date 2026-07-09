<?php

namespace App\Contracts;

use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;

interface EventServiceInterface
{
    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    public function list(int $perPage, array $filters = [], ?int $userId = null, ?string $userRole = null): array;

    /** @return array<string, mixed>|null */
    public function findById(int $id, ?int $userId = null, ?string $userRole = null): ?array;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function create(array $data, int $creatorId, ?string $creatorRole = null, ?int $creatorClassYearId = null): array;

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function update(int $id, array $data): array;

    public function delete(int $id): void;

    /** @param array<int, int>|int|null $servantClassIds */
    /** @return array<string, mixed> */
    public function viewSummary(int $eventId, array|int|null $servantClassIds = null): array;

    /** @param array<string, mixed> $filters */
    /** @param array<int, int>|int|null $servantClassIds */
    public function viewedUsers(int $eventId, array $filters = [], array|int|null $servantClassIds = null): Collection;

    /** @param array<string, mixed> $filters */
    /** @param array<int, int>|int|null $servantClassIds */
    public function notViewedUsers(int $eventId, ?int $churchId = null, array $filters = [], array|int|null $servantClassIds = null): Collection;

    public function trackView(int $eventId, int $userId, ?string $ipAddress = null, ?string $userAgent = null): void;
}
