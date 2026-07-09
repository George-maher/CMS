<?php

namespace App\Contracts;

interface AttendanceServiceInterface
{
    /** @return array<string, mixed> */
    public function recordAttendance(string $qrToken, int $recordedBy, int $contextId, ?int $eventId = null, string $method = 'qr'): array;
    /** @return array<string, mixed> */
    public function recordAttendanceByMemberId(string $memberId, int $recordedBy, int $contextId, ?int $eventId = null, string $method = 'id'): array;
    /** @return array<string, mixed> */
    public function getAttendanceHistory(int $userId, int $perPage = 15): array;
    /** @param array<int, int>|int|null $classYearIds */
    /** @return array<string, mixed> */
    public function getTodayAttendance(array|int|null $classYearIds = null, int $perPage = 15): array;
    /** @return array<string, mixed> */
    public function getAttendanceByClass(int $classYearId, ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 15): array;

    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    public function getFilteredAttendances(array $filters, int $perPage = 15): array;

    /** @return array<string, mixed> */
    public function getAttendanceStats(?int $userId = null): array;
    /** @param array<int, int>|int|null $classYearIds */
    /** @return array<string, mixed> */
    public function getContextSummary(?string $dateFrom = null, ?string $dateTo = null, array|int|null $classYearIds = null): array;
    /** @param array<int, int>|int|null $classYearId */
    /** @return array<string, mixed> */
    public function getContextAnalytics(int $contextId, array|int|null $classYearId = null, ?int $servantId = null, ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 15): array;
    /** @return array<string, mixed> */
    public function getAbsentMembers(int $classYearId, ?int $eventId = null, ?int $contextId = null, ?string $date = null, ?string $dateFrom = null, ?string $dateTo = null): array;
}
