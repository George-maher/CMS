<?php

namespace App\Http\Controllers\Api;

use App\Contracts\LeaderboardServiceInterface;
use App\Contracts\ScopeResolverInterface;
use App\Enums\UserScope;
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
        /** @var User $user */
        $user = request()->user();

        // Stage/class-scoped principals must not receive church-wide rankings.
        // Church-scope (admin/assistant_admin) and self-scope (member,
        // gamification view) keep the existing church-wide leaderboard. A
        // stage admin without a valid stage assignment fails closed.
        $scope = $this->scopeResolver->getScope($user);
        if ($scope === UserScope::Church) {
            $classIds = null;
        } elseif ($user->isStageAdmin() && $scope !== UserScope::Stage) {
            $classIds = [];
        } elseif (in_array($scope, [UserScope::Stage, UserScope::ClassScope], true)) {
            $classIds = $this->scopeResolver->allowedClassIds($user) ?? [];
        } else {
            $classIds = null;
        }

        $result = $this->leaderboardService->globalLeaderboard(5, $classIds);

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
        /** @var User $user */
        $user = request()->user();

        // Stage admins only receive their own stage's aggregates; class-scoped
        // principals only the stages containing their classes. Church-scope
        // principals keep the full church view, while a stage admin without a
        // valid stage assignment fails closed.
        $scope = $this->scopeResolver->getScope($user);
        if ($user->isStageAdmin() && $scope !== UserScope::Stage) {
            $stageIds = [];
        } elseif ($scope === UserScope::Stage) {
            $stageIds = $user->stage_id !== null ? [(int) $user->stage_id] : [];
        } elseif ($scope === UserScope::ClassScope) {
            $stageIds = $this->scopeResolver->allowedStageIds($user);
        } else {
            $stageIds = null;
        }

        $result = $this->leaderboardService->stagesLeaderboards($stageIds);

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
