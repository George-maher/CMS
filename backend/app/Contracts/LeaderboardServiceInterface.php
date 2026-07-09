<?php

namespace App\Contracts;

interface LeaderboardServiceInterface
{
    /** @return array<string, mixed> */
    public function classLeaderboard(int $classId, int $limit = 3): array;

    /** @return array<string, mixed> */
    public function globalLeaderboard(int $limit = 5): array;

    /** @return array<int, array<string, mixed>> */
    public function stagesLeaderboards(): array;

    /** @return array<string, mixed> */
    public function memberClassLeaderboard(int $userId, int $limit = 3): array;
}
