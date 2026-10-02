<?php

namespace App\Contracts;

interface PointServiceInterface
{
    /** @return array<string, mixed> */
    public function addPoints(int $userId, int $points, string $type, ?string $description = null, ?string $referenceType = null, ?int $referenceId = null): array;

    /** @return array<string, mixed> */
    public function addBonusPoints(int $userId, int $points, int $addedBy, ?string $reason = null): array;

    public function getPointsBalance(int $userId): int;

    /** @return array<string, mixed> */
    public function getPointsHistory(int $userId, int $perPage = 15): array;

    /** @return array<string, mixed> */
    public function getLeaderboard(int $limit = 10): array;
}
