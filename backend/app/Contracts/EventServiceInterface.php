<?php

namespace App\Contracts;

use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;

interface EventServiceInterface
{
    public function list(int $perPage, array $filters = [], ?int $userId = null, ?string $userRole = null): array;
    public function findById(int $id, ?int $userId = null, ?string $userRole = null): ?array;
    public function create(array $data, int $creatorId, ?string $creatorRole = null, ?int $creatorClassYearId = null): array;
    public function update(int $id, array $data): array;
    public function delete(int $id): void;

    public function viewSummary(int $eventId, array|int|null $servantClassIds = null): array;
    public function viewedUsers(int $eventId, array $filters = [], array|int|null $servantClassIds = null): Collection;
    public function notViewedUsers(int $eventId, ?int $churchId = null, array $filters = [], array|int|null $servantClassIds = null): Collection;
    public function trackView(int $eventId, int $userId, ?string $ipAddress = null, ?string $userAgent = null): void;
}
