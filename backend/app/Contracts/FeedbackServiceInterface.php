<?php

namespace App\Contracts;

interface FeedbackServiceInterface
{
    /** @param array<string, mixed> $data */
    /** @param array<int, int>|int|null $classYearId */
    /** @return array<string, mixed> */
    public function submit(array $data, ?int $userId = null, array|int|null $classYearId = null): array;

    /** @param array<string, mixed> $filters */
    /** @param array<int, int>|int|null $classYearIds */
    /** @return array<string, mixed> */
    public function list(int $perPage = 15, array $filters = [], array|int|null $classYearIds = null): array;

    /** @return array<string, mixed> */
    public function markAsResolved(int $id): array;
    /** @return array<string, mixed> */
    public function reply(int $feedbackId, int $userId, string $message): array;
    /** @return array<string, mixed> */
    public function markAsSeen(int $feedbackId, int $userId): array;
}
