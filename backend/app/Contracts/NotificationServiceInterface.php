<?php

namespace App\Contracts;

interface NotificationServiceInterface
{
    public function listForUser(int $userId, int $perPage = 15): array;
    public function unreadCount(int $userId): int;
    public function markAsRead(int $notificationId, int $userId): void;
    public function markAllAsRead(int $userId): void;
    public function createForEvent(array $targetUserIds, int $eventId, int $churchId, string $title, string $body): void;
    public function createForFeedbackReply(int $feedbackId, int $userId, int $churchId, string $title, string $body): void;
    public function createForBonusPoints(int $pointsId, int $userId, int $churchId, string $title, string $body): void;
    public function create(int $userId, int $churchId, string $title, string $body, string $type = 'general'): void;
}
