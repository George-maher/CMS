<?php

namespace App\Contracts;

interface LeaderboardServiceInterface
{
    public function classLeaderboard(int $classId, int $limit = 3): array;

    public function globalLeaderboard(int $limit = 5): array;

    public function stagesLeaderboards(): array;

    public function memberClassLeaderboard(int $userId, int $limit = 3): array;
}
