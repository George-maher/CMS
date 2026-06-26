<?php

namespace App\Contracts;

interface FeedbackServiceInterface
{
    public function submit(array $data, ?int $userId = null, array|int|null $classYearId = null): array;
    public function list(int $perPage = 15, array $filters = [], array|int|null $classYearIds = null): array;
    public function markAsResolved(int $id): array;
    public function reply(int $feedbackId, int $userId, string $message): array;
    public function markAsSeen(int $feedbackId, int $userId): array;
}
