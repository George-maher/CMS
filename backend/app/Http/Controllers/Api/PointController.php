<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PointServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddBonusPointsRequest;
use App\Http\Resources\PointResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PointController extends Controller
{
    public function __construct(
        private readonly PointServiceInterface $pointService,
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
        /** @var int $limit */
        $limit = $request->integer('limit', 10);
        $result = $this->pointService->getLeaderboard(
            limit: $limit,
        );

        return response()->json([
            'data' => $result['data'],
        ]);
    }

    public function userBalance(int $userId): JsonResponse
    {
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
}
