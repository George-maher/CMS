<?php

namespace App\Contracts;

interface AttendanceServiceInterface
{
    public function recordAttendance(string $qrToken, int $recordedBy, int $contextId, ?int $eventId = null, string $method = 'qr'): array;
    public function recordAttendanceByMemberId(string $memberId, int $recordedBy, int $contextId, ?int $eventId = null, string $method = 'id'): array;
    public function getAttendanceHistory(int $userId, int $perPage = 15): array;
    public function getTodayAttendance(array|int|null $classYearIds = null, int $perPage = 15): array;
    public function getAttendanceByClass(int $classYearId, ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 15): array;
    public function getFilteredAttendances(array $filters, int $perPage = 15): array;
    public function getAttendanceStats(int $userId = null): array;
    public function getContextSummary(?string $dateFrom = null, ?string $dateTo = null, array|int|null $classYearIds = null): array;
    public function getContextAnalytics(int $contextId, array|int|null $classYearId = null, ?int $servantId = null, ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 15): array;
    public function getAbsentMembers(int $classYearId, ?int $eventId = null, ?int $contextId = null, ?string $date = null, ?string $dateFrom = null, ?string $dateTo = null): array;
}
