<?php

namespace App\Contracts;

interface LeaderboardServiceInterface
{
    /** @return array<string, mixed> */
    public function classLeaderboard(int $classId, int $limit = 3): array;

    /**
     * @param  array<int, int>|null  $classIds  null = church-wide
     * @return array<string, mixed>
     */
    public function globalLeaderboard(int $limit = 5, ?array $classIds = null): array;

    /**
     * @param  array<int, int>|null  $stageIds  null = every stage in the church
     * @return array<int, array<string, mixed>>
     */
    public function stagesLeaderboards(?array $stageIds = null): array;

    /** @return array<string, mixed> */
    public function memberClassLeaderboard(int $userId, int $limit = 3): array;
}
