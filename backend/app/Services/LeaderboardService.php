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

    /** @return array<string, mixed> */
    public function classLeaderboard(int $classId, int $limit = 3): array
    {
        $churchId = $this->getAuthChurchId();

        return $this->cacheService->rememberLeaderboard(
            $churchId,
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

    /** @return array<string, mixed> */
    public function globalLeaderboard(int $limit = 5): array
    {
        $churchId = $this->getAuthChurchId();

        return $this->cacheService->rememberLeaderboard(
            $churchId,
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

    /** @return array<int, array<string, mixed>> */
    public function stagesLeaderboards(): array
    {
        $churchId = $this->getAuthChurchId();

        return $this->cacheService->rememberStagesLeaderboards(
            $churchId,
            function () {
                $stages = Stage::byChurch()
                    ->with(['classes' => function (\Illuminate\Database\Eloquent\Relations\Relation $query) {
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

    /** @return array<string, mixed> */
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

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\User> */
    private function baseLeaderboardQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $churchId = $this->getAuthChurchId();

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

    private function getAuthChurchId(): ?int
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();
        return $user?->church_id;
    }

    /**
     * @param \Illuminate\Support\Collection<int, \App\Models\User>|\Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $members
     * @return array<int, array<string, mixed>>
     */
    private function formatLeaderboard(\Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection $members): array
    {
        $entries = [];
        $rank = 1;

        foreach ($members as $member) {
            /** @var int $totalPoints */
            $totalPoints = $member->total_points;
            /** @var mixed $attendanceCount */
            $attendanceCount = $member->attendance_count;
            $entries[] = [
                'rank' => $rank,
                'user_id' => $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar,
                'class_name' => $member->classe?->name,
                'stage_name' => $member->classe?->stage?->name,
                'total_points' => $totalPoints,
                'attendance_count' => $attendanceCount,
            ];
            $rank++;
        }

        return $entries;
    }
}
