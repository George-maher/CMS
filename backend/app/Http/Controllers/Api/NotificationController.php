<?php

namespace App\Http\Controllers\Api;

use App\Contracts\NotificationServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationServiceInterface $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        /** @var int $userId */
        $userId = $user->id;

        $result = $this->notificationService->listForUser(
            userId: $userId,
            perPage: $request->integer('per_page', 15),
        );

        return response()->json($result);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        /** @var int $userId */
        $userId = $user->id;

        $count = $this->notificationService->unreadCount($userId);

        return response()->json([
            'data' => [
                'unread_count' => $count,
            ],
        ]);
    }

    public function markAsRead(Request $request, int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        /** @var int $userId */
        $userId = $user->id;

        $this->notificationService->markAsRead($id, $userId);

        return response()->json([
            'message' => 'Notification marked as read.',
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        /** @var int $userId */
        $userId = $user->id;

        $this->notificationService->markAllAsRead($userId);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }
}
