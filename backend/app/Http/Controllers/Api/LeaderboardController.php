<?php

namespace App\Http\Controllers\Api;

use App\Contracts\LeaderboardServiceInterface;
use App\Contracts\ScopeResolverInterface;
use App\Http\Controllers\Controller;
use App\Models\Classe;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class LeaderboardController extends Controller
{
    public function __construct(
        private readonly LeaderboardServiceInterface $leaderboardService,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function global(): JsonResponse
    {
        $result = $this->leaderboardService->globalLeaderboard(5);

        return response()->json([
            'data' => $result['leaderboard'],
        ]);
    }

    public function byClass(int $classId): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        $classe = Classe::byChurch()->findOrFail($classId);

        if (! $this->scopeResolver->canAccessClass($user, $classe)) {
            abort(403, 'You can only view leaderboards for classes within your scope.');
        }

        $result = $this->leaderboardService->classLeaderboard($classId, 3);

        return response()->json([
            'data' => $result,
        ]);
    }

    public function stages(): JsonResponse
    {
        $result = $this->leaderboardService->stagesLeaderboards();

        return response()->json([
            'data' => $result,
        ]);
    }

    public function myClass(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        if (! $user->class_id) {
            return response()->json([
                'data' => [
                    'class' => null,
                    'stage' => null,
                    'leaderboard' => [],
                ],
            ]);
        }

        $result = $this->leaderboardService->classLeaderboard($user->class_id, 3);

        return response()->json([
            'data' => $result,
        ]);
    }

    public function myClasses(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        if ($user->isStageAdmin()) {
            /** @var array<int, int> $classIds */
            $classIds = $this->scopeResolver->allowedClassIds($user) ?? [];
        } else {
            /** @var array<int, int> $classIds */
            $classIds = $user->getServantClassIds() ?? [];
        }

        if (empty($classIds)) {
            return response()->json([
                'data' => [],
            ]);
        }

        $result = [];
        foreach ($classIds as $classId) {
            $result[] = $this->leaderboardService->classLeaderboard($classId, 3);
        }

        return response()->json([
            'data' => $result,
        ]);
    }
}
