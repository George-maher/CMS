<?php

namespace App\Contracts;

interface PointServiceInterface
{
    public function addPoints(int $userId, int $points, string $type, ?string $description = null, ?string $referenceType = null, ?int $referenceId = null): array;
    public function addBonusPoints(int $userId, int $points, int $addedBy, ?string $reason = null): array;
    public function getPointsBalance(int $userId): int;
    public function getPointsHistory(int $userId, int $perPage = 15): array;
    public function getLeaderboard(int $limit = 10): array;
}
