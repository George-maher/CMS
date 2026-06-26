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
        $user = $request->user();

        $result = $this->notificationService->listForUser(
            userId: $user->id,
            perPage: (int) $request->input('per_page', 15),
        );

        return response()->json($result);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = $this->notificationService->unreadCount($user->id);

        return response()->json([
            'data' => [
                'unread_count' => $count,
            ],
        ]);
    }

    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $this->notificationService->markAsRead($id, $user->id);

        return response()->json([
            'message' => 'Notification marked as read.',
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->notificationService->markAllAsRead($user->id);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }
}
