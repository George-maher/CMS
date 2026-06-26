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

    public function addPoints(int $userId, int $points, string $type, ?string $description = null, ?string $referenceType = null, ?int $referenceId = null): array
    {
        $point = $this->pointRepository->create([
            'user_id' => $userId,
            'points' => $points,
            'type' => $type,
            'description' => $description,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        $user = User::find($userId);
        $this->cacheService->invalidatePoints($user?->church_id);
        $this->cacheService->invalidateDashboard($user?->church_id);

        return [
            'point' => $point,
            'balance' => $this->getPointsBalance($userId),
        ];
    }

    public function addBonusPoints(int $userId, int $points, int $addedBy, ?string $reason = null): array
    {
        $member = User::byChurch()->find($userId);
        if (!$member) {
            throw ValidationException::withMessages([
                'user_id' => ['Member not found.'],
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
            churchId: $member->church_id,
            title: 'Bonus Points Added',
            body: "You received {$points} bonus points." . ($reason ? " Reason: {$reason}" : ''),
        );

        $this->cacheService->invalidatePoints($member->church_id);
        $this->cacheService->invalidateDashboard($member->church_id);

        return [
            'point' => $point,
            'balance' => $this->getPointsBalance($userId),
        ];
    }

    public function getPointsBalance(int $userId): int
    {
        return $this->pointRepository->getTotalPointsByUser($userId);
    }

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

    public function getLeaderboard(int $limit = 10): array
    {
        $topUsers = User::byChurch()
            ->withSum('points as total_points', 'points')
            ->byRole(UserRole::Member)
            ->orderByDesc('total_points')
            ->limit($limit)
            ->get()
            ->map(function ($user, $index) {
                return [
                    'rank' => $index + 1,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'total_points' => (int) $user->total_points,
                ];
            });

        return [
            'data' => $topUsers,
        ];
    }
}
