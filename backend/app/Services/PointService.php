<?php

namespace App\Services;

use App\Contracts\NotificationServiceInterface;
use App\Contracts\PointRepositoryInterface;
use App\Contracts\PointServiceInterface;
use App\Enums\PointType;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PointService implements PointServiceInterface
{
    public function __construct(
        private readonly PointRepositoryInterface $pointRepository,
        private readonly NotificationServiceInterface $notificationService,
        private readonly CacheService $cacheService,
    ) {}

    /** @return array<string, mixed> */
    public function addPoints(int $userId, int $points, string $type, ?string $description = null, ?string $referenceType = null, ?int $referenceId = null): array
    {
        $user = User::find($userId);
        if (! $user || ! $user->isMember()) {
            throw ValidationException::withMessages([
                'user_id' => ['Only members can receive points.'],
            ]);
        }

        $point = $this->pointRepository->create([
            'user_id' => $userId,
            'points' => $points,
            'type' => $type,
            'description' => $description,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        $this->cacheService->invalidatePoints($user->church_id);
        $this->cacheService->invalidateDashboard($user->church_id);

        return [
            'point' => $point,
            'balance' => $this->getPointsBalance($userId),
        ];
    }

    /** @return array<string, mixed> */
    public function addBonusPoints(int $userId, int $points, int $addedBy, ?string $reason = null): array
    {
        $user = User::byChurch()->find($userId);
        if (! $user) {
            throw ValidationException::withMessages([
                'user_id' => ['User not found.'],
            ]);
        }

        if (! $user->isMember()) {
            throw ValidationException::withMessages([
                'user_id' => ['Only members can receive bonus points.'],
            ]);
        }

        $churchId = $user->church_id;
        if ($churchId === null) {
            throw ValidationException::withMessages([
                'user_id' => ['Member must belong to a church to receive bonus points.'],
            ]);
        }

        $point = $this->pointRepository->create([
            'user_id' => $userId,
            'points' => $points,
            'type' => PointType::Bonus->value,
            'added_by' => $addedBy,
            'description' => $reason ?? 'Bonus points awarded',
        ]);

        $this->notificationService->createForBonusPoints(
            pointsId: $point->id,
            userId: $userId,
            churchId: $churchId,
            title: 'Bonus Points Added',
            body: "You received {$points} bonus points.".($reason ? " Reason: {$reason}" : ''),
        );

        $this->cacheService->invalidatePoints($churchId);
        $this->cacheService->invalidateDashboard($churchId);

        return [
            'point' => $point,
            'balance' => $this->getPointsBalance($userId),
        ];
    }

    public function getPointsBalance(int $userId): int
    {
        return $this->pointRepository->getTotalPointsByUser($userId);
    }

    /** @return array<string, mixed> */
    public function getPointsHistory(int $userId, int $perPage = 15): array
    {
        $paginator = $this->pointRepository->paginate($perPage, ['user_id' => $userId]);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @param  array<int, int>|null  $classIds
     * @return array<string, mixed>
     */
    public function getLeaderboard(int $limit = 10, ?array $classIds = null, ?int $stageId = null): array
    {
        // The limit arrives from a query-string parameter, so it is bounded
        // here as well as in the controller: the ranking query must never be
        // able to return an unbounded result set.
        $limit = max(1, min($limit, PointServiceInterface::MAX_LEADERBOARD_LIMIT));

        $topUsers = User::byChurch()
            ->withSum('points as total_points', 'points')
            ->byRole(UserRole::Member)
            ->when($stageId !== null || $classIds !== null, function ($query) use ($stageId, $classIds) {
                $query->where(function ($q) use ($stageId, $classIds) {
                    if ($stageId !== null) {
                        $q->where('stage_id', $stageId);
                    }
                    if (! empty($classIds)) {
                        // `users.class_year_id` is deliberately NOT used here.
                        // It is the one remaining `class_year_id` still
                        // foreign-keyed to the deprecated `class_years` table
                        // (see CreateUserRequest and EventPolicy); every other
                        // one was repointed at `classes` by 2026_06_18_000002.
                        // Comparing it against `classes.id` compares two id
                        // spaces, so a member whose legacy class_years id
                        // happened to equal an allowed class id would be pulled
                        // into a ranking they do not belong to. `class_id` is
                        // what ScopeResolver::canAccessUser() and
                        // userClassId() also resolve against.
                        $q->orWhereIn('class_id', $classIds);
                    }
                    if ($stageId === null && empty($classIds)) {
                        $q->whereRaw('1 = 0');
                    }
                });
            })
            ->orderByDesc('total_points')
            ->limit($limit)
            ->get()
            ->map(function ($user, $index) {
                /** @var int $totalPoints */
                $totalPoints = $user->total_points;

                // Minimum necessary fields only: this ranking is readable by
                // any approved user, so no contact data (email/phone) is
                // exposed. LeaderboardService::formatLeaderboard() omits email
                // for the same reason.
                return [
                    'rank' => $index + 1,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'total_points' => $totalPoints,
                ];
            });

        return [
            'data' => $topUsers,
        ];
    }
}
