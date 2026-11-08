<?php

namespace App\Contracts;

interface PointServiceInterface
{
    /**
     * Leaderboard rows returned when the caller does not request a count.
     */
    public const DEFAULT_LEADERBOARD_LIMIT = 10;

    /**
     * Hard ceiling on leaderboard rows.
     *
     * The value is enforced inside the service as well as in the controller so
     * the ranking query is bounded even if a new caller forgets to clamp it.
     */
    public const MAX_LEADERBOARD_LIMIT = 100;

    /** @return array<string, mixed> */
    public function addPoints(int $userId, int $points, string $type, ?string $description = null, ?string $referenceType = null, ?int $referenceId = null): array;

    /** @return array<string, mixed> */
    public function addBonusPoints(int $userId, int $points, int $addedBy, ?string $reason = null): array;

    public function getPointsBalance(int $userId): int;

    /** @return array<string, mixed> */
    public function getPointsHistory(int $userId, int $perPage = 15): array;

    /**
     * @param  array<int, int>|null  $classIds  restrict the ranking to these classes
     * @param  int|null  $stageId  restrict the ranking to this stage
     * @return array<string, mixed>
     */
    public function getLeaderboard(int $limit = 10, ?array $classIds = null, ?int $stageId = null): array;
}
