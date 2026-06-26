<?php

namespace App\Services;

use App\Contracts\NotificationServiceInterface;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

class NotificationService implements NotificationServiceInterface
{
    public function listForUser(int $userId, int $perPage = 15): array
    {
        $paginator = Notification::forUser($userId)
            ->with(['event', 'feedback', 'point'])
            ->latest()
            ->paginate($perPage);

        return [
            'data' => NotificationResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function unreadCount(int $userId): int
    {
        return Notification::forUser($userId)
            ->unread()
            ->count();
    }

    public function markAsRead(int $notificationId, int $userId): void
    {
        Notification::forUser($userId)
            ->where('id', $notificationId)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    public function markAllAsRead(int $userId): void
    {
        Notification::forUser($userId)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    public function createForEvent(array $targetUserIds, int $eventId, int $churchId, string $title, string $body): void
    {
        if (empty($targetUserIds)) {
            return;
        }

        $now = now();
        $inserts = [];

        foreach ($targetUserIds as $userId) {
            $inserts[] = [
                'church_id' => $churchId,
                'user_id' => $userId,
                'event_id' => $eventId,
                'title' => $title,
                'body' => $body,
                'type' => 'event',
                'is_read' => false,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('notifications')->insert($inserts);
    }

    public function createForFeedbackReply(int $feedbackId, int $userId, int $churchId, string $title, string $body): void
    {
        Notification::create([
            'church_id' => $churchId,
            'user_id' => $userId,
            'feedback_id' => $feedbackId,
            'title' => $title,
            'body' => $body,
            'type' => 'feedback_reply',
            'is_read' => false,
        ]);
    }

    public function createForBonusPoints(int $pointsId, int $userId, int $churchId, string $title, string $body): void
    {
        Notification::create([
            'church_id' => $churchId,
            'user_id' => $userId,
            'points_id' => $pointsId,
            'title' => $title,
            'body' => $body,
            'type' => 'bonus_points',
            'is_read' => false,
        ]);
    }

    public function create(int $userId, int $churchId, string $title, string $body, string $type = 'general'): void
    {
        Notification::create([
            'church_id' => $churchId,
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'is_read' => false,
        ]);
    }
}
