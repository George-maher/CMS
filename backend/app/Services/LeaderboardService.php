<?php

namespace App\Services;

use App\Contracts\LeaderboardServiceInterface;
use App\Enums\UserRole;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeaderboardService implements LeaderboardServiceInterface
{
    public function __construct(
        private readonly CacheService $cacheService,
    ) {}

    public function classLeaderboard(int $classId, int $limit = 3): array
    {
        return $this->cacheService->rememberLeaderboard(
            auth()->user()->church_id,
            $classId,
            $limit,
            function () use ($classId, $limit) {
                $classe = Classe::byChurch()->findOrFail($classId);

                $members = $this->baseLeaderboardQuery()
                    ->where('users.class_id', $classId)
                    ->limit($limit)
                    ->get();

                return [
                    'class' => [
                        'id' => $classe->id,
                        'name' => $classe->name,
                    ],
                    'stage' => $classe->stage ? [
                        'id' => $classe->stage->id,
                        'name' => $classe->stage->name,
                    ] : null,
                    'leaderboard' => $this->formatLeaderboard($members),
                ];
            }
        );
    }

    public function globalLeaderboard(int $limit = 5): array
    {
        return $this->cacheService->rememberLeaderboard(
            auth()->user()->church_id,
            null,
            $limit,
            function () use ($limit) {
                $members = $this->baseLeaderboardQuery()
                    ->limit($limit)
                    ->get();

                return [
                    'leaderboard' => $this->formatLeaderboard($members),
                ];
            }
        );
    }

    public function stagesLeaderboards(): array
    {
        return $this->cacheService->rememberDashboardStats(
            auth()->user()->church_id,
            function () {
                $stages = Stage::byChurch()
                    ->with(['classes' => function ($query) {
                        $query->orderBy('display_order');
                    }])
                    ->orderBy('display_order')
                    ->get();

                $result = [];

                foreach ($stages as $stage) {
                    $stageData = [
                        'stage_id' => $stage->id,
                        'stage_name' => $stage->name,
                        'classes' => [],
                    ];

                    foreach ($stage->classes as $classe) {
                        $members = $this->baseLeaderboardQuery()
                            ->where('users.class_id', $classe->id)
                            ->limit(3)
                            ->get();

                        $stageData['classes'][] = [
                            'id' => $classe->id,
                            'name' => $classe->name,
                            'leaderboard' => $this->formatLeaderboard($members),
                        ];
                    }

                    $result[] = $stageData;
                }

                return $result;
            }
        );
    }

    public function memberClassLeaderboard(int $userId, int $limit = 3): array
    {
        $user = User::byChurch()->findOrFail($userId);

        if (!$user->class_id) {
            return [
                'class' => null,
                'stage' => null,
                'leaderboard' => [],
            ];
        }

        return $this->classLeaderboard($user->class_id, $limit);
    }

    private function baseLeaderboardQuery()
    {
        $churchId = auth()->user()->church_id;

        return User::select([
            'users.id',
            'users.name',
            'users.avatar',
            'users.class_id',
            'users.created_at',
            DB::raw('COALESCE((SELECT SUM(points) FROM points WHERE user_id = users.id), 0) as total_points'),
            DB::raw('COALESCE((SELECT COUNT(*) FROM attendances WHERE user_id = users.id), 0) as attendance_count'),
        ])
            ->where('users.church_id', $churchId)
            ->where('users.role', UserRole::Member)
            ->where('users.is_active', true)
            ->with('classe.stage')
            ->orderByRaw('total_points DESC')
            ->orderByRaw('attendance_count DESC')
            ->orderBy('users.created_at');
    }

    private function formatLeaderboard($members): array
    {
        $entries = [];
        $rank = 1;

        foreach ($members as $member) {
            $entries[] = [
                'rank' => $rank,
                'user_id' => $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar,
                'class_name' => $member->classe?->name,
                'stage_name' => $member->classe?->stage?->name,
                'total_points' => (int) $member->total_points,
                'attendance_count' => (int) $member->attendance_count,
            ];
            $rank++;
        }

        return $entries;
    }
}
