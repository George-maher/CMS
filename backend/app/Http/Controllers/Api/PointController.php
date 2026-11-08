<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PointServiceInterface;
use App\Contracts\ScopeResolverInterface;
use App\Enums\UserScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddBonusPointsRequest;
use App\Http\Resources\PointResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PointController extends Controller
{
    public function __construct(
        private readonly PointServiceInterface $pointService,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    public function balance(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $userId */
        $userId = $user->id;
        $balance = $this->pointService->getPointsBalance($userId);

        return response()->json([
            'data' => [
                'balance' => $balance,
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $perPage */
        $perPage = $request->integer('per_page', 15);
        /** @var int $userId */
        $userId = $user->id;
        $result = $this->pointService->getPointsHistory(
            userId: $userId,
            perPage: $perPage,
        );

        return response()->json([
            'data' => PointResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function leaderboard(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $limit = $this->normalizeLeaderboardLimit(
            $request->integer('limit', PointServiceInterface::DEFAULT_LEADERBOARD_LIMIT)
        );

        // Stage/class-scoped principals only rank members inside their scope;
        // church-scope principals keep the church-wide ranking.
        $scope = $this->scopeResolver->getScope($user);
        $classIds = null;
        $stageId = null;
        if ($user->isStageAdmin() && $scope !== UserScope::Stage) {
            $classIds = []; // stage admin without a stage: fail closed
        } elseif ($scope === UserScope::Stage) {
            $stageId = $user->stage_id !== null ? (int) $user->stage_id : null;
            $classIds = $this->scopeResolver->allowedClassIds($user) ?? [];
        } elseif ($scope === UserScope::ClassScope || $scope === UserScope::Self) {
            // Servants (ClassScope) and members (Self) must never see the
            // church-wide ranking. `allowedClassIds` resolves a servant's
            // classes and a member's own class, and returns an empty list for a
            // member without a class — which fails closed inside the service.
            $classIds = $this->scopeResolver->allowedClassIds($user) ?? [];
        }

        $result = $this->pointService->getLeaderboard(
            limit: $limit,
            classIds: $classIds,
            stageId: $stageId,
        );

        return response()->json([
            'data' => $result['data'],
        ]);
    }

    /**
     * Normalise the client-supplied leaderboard limit.
     *
     * `Request::integer()` yields 0 for a missing, empty or non-numeric value,
     * so anything below 1 falls back to the documented default and anything
     * above the documented maximum is clamped rather than rejected: the
     * endpoint keeps its "always answers" contract while the underlying query
     * stays bounded.
     */
    private function normalizeLeaderboardLimit(int $limit): int
    {
        if ($limit < 1) {
            return PointServiceInterface::DEFAULT_LEADERBOARD_LIMIT;
        }

        return min($limit, PointServiceInterface::MAX_LEADERBOARD_LIMIT);
    }

    public function userBalance(int $userId): JsonResponse
    {
        $guard = $this->guardTargetUser($userId);
        if ($guard !== null) {
            return $guard;
        }

        $balance = $this->pointService->getPointsBalance($userId);

        return response()->json([
            'data' => [
                'user_id' => $userId,
                'balance' => $balance,
            ],
        ]);
    }

    public function userHistory(Request $request, int $userId): JsonResponse
    {
        $guard = $this->guardTargetUser($userId);
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->pointService->getPointsHistory(
            userId: $userId,
            perPage: $request->integer('per_page', 15)
        );

        return response()->json([
            'data' => PointResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function addBonusPoints(AddBonusPointsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $targetUserId = $request->integer('user_id');
        $targetUser = User::byChurch()->find($targetUserId);

        if (! $targetUser) {
            throw ValidationException::withMessages([
                'user_id' => ['User not found in your church.'],
            ]);
        }

        if (! $targetUser->isMember()) {
            throw ValidationException::withMessages([
                'user_id' => ['Only members can receive bonus points.'],
            ]);
        }

        // Church scoping above is not enough: stage/class scoped actors must
        // not award points to members outside their own scope.
        if (! $this->scopeResolver->canAccessUser($user, $targetUser)) {
            throw ValidationException::withMessages([
                'user_id' => ['User not in your scope.'],
            ]);
        }

        /** @var int $points */
        $points = $request->integer('points');
        $reason = (string) $request->str('reason');
        /** @var int $addedBy */
        $addedBy = $user->id;
        $result = $this->pointService->addBonusPoints(
            userId: $targetUserId,
            points: $points,
            addedBy: $addedBy,
            reason: $reason,
        );

        return response()->json([
            'message' => 'Bonus points awarded successfully.',
            'data' => [
                'point' => new PointResource($result['point']),
                'balance' => $result['balance'],
            ],
        ], 201);
    }

    /**
     * Tenant + scope guard for cross-user point reads: platform admins may
     * read anyone; everyone else is limited to users resolvable inside their
     * own church (User is not globally tenant-scoped, so this guard is the
     * tenant boundary) and inside their own stage/class scope.
     */
    private function guardTargetUser(int $userId): ?JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        if ($actor === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($actor->isPlatformAdmin()) {
            return null;
        }

        $target = User::byChurch()->find($userId);
        if ($target === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (! $this->scopeResolver->canAccessUser($actor, $target)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return null;
    }
}
