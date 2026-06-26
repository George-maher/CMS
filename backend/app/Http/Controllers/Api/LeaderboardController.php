<?php

namespace App\Http\Controllers\Api;

use App\Contracts\LeaderboardServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Classe;
use Illuminate\Http\JsonResponse;

class LeaderboardController extends Controller
{
    public function __construct(
        private readonly LeaderboardServiceInterface $leaderboardService,
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
        $user = request()->user();

        $classe = Classe::byChurch()->findOrFail($classId);

        if ($user->isServant()) {
            $servantClassIds = $user->getServantClassIds() ?? [];
            if (!in_array($classId, $servantClassIds)) {
                abort(403, 'You can only view leaderboards for your assigned classes.');
            }
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
        $user = request()->user();

        if (!$user->class_id) {
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
        $user = request()->user();

        $classIds = $user->getServantClassIds() ?? [];

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
