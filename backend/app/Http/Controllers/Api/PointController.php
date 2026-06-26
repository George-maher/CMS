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
        $balance = $this->pointService->getPointsBalance($request->user()->id);

        return response()->json([
            'data' => [
                'balance' => $balance,
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $result = $this->pointService->getPointsHistory(
            userId: $request->user()->id,
            perPage: $request->input('per_page', 15)
        );

        return response()->json([
            'data' => PointResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $result = $this->pointService->getLeaderboard(
            limit: $request->input('limit', 10)
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
            perPage: $request->input('per_page', 15)
        );

        return response()->json([
            'data' => PointResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function addBonusPoints(AddBonusPointsRequest $request): JsonResponse
    {
        $user = $request->user();
        $targetUserId = (int) $request->input('user_id');
        $targetUser = User::byChurch()->find($targetUserId);

        if (!$targetUser) {
            throw ValidationException::withMessages([
                'user_id' => ['User not found in your church.'],
            ]);
        }

        $result = $this->pointService->addBonusPoints(
            userId: $targetUserId,
            points: (int) $request->input('points'),
            addedBy: $user->id,
            reason: $request->input('reason'),
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
