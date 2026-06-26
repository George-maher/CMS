<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface AttendanceRepositoryInterface
{
    public function findById(int $id);
    public function create(array $data);
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function hasAttendanceToday(int $userId, ?int $eventId = null, ?int $contextId = null): bool;
    public function getAttendanceCountByUser(int $userId): int;
    public function getTodayAttendanceByClass(int $classYearId): Collection;
    public function getAttendanceByClassYear(int $classYearId, ?string $dateFrom = null, ?string $dateTo = null): Collection;
    public function getAttendanceByDateRange(string $startDate, string $endDate): Collection;
    public function getAttendanceByUserAndDateRange(int $userId, string $startDate, string $endDate): Collection;
    public function getContextSummary(?string $dateFrom = null, ?string $dateTo = null, array|int|null $classYearIds = null): Collection;
    public function getContextAnalytics(int $contextId, array|int|null $classYearId = null, ?int $servantId = null, ?string $dateFrom = null, ?string $dateTo = null, ?int $dayOfWeek = null): Collection;
    public function getMembersByClassYear(int $classYearId): Collection;
    public function getAttendedUserIds(int $classYearId, ?int $eventId = null, ?int $contextId = null, ?string $date = null, ?string $dateFrom = null, ?string $dateTo = null): array;
    public function getLastAttendanceByUser(int $userId): ?\App\Models\Attendance;
    public function getAttendanceCountByUserAndContext(int $userId, int $contextId): int;
    public function getConsecutiveAbsences(int $userId, int $contextId, string $currentDate): int;
    public function getMonthAbsenceCount(int $userId, int $contextId, int $year, int $month): int;
    public function getTotalSessionsCount(?int $contextId, ?int $classYearId = null): int;

    // Paginated alternatives for large datasets
    public function paginateContextAnalytics(int $contextId, int $perPage = 15, array|int|null $classYearId = null, ?int $servantId = null, ?string $dateFrom = null, ?string $dateTo = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator;
    public function paginateAttendanceByClassYear(int $classYearId, int $perPage = 15, ?string $dateFrom = null, ?string $dateTo = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator;
    public function paginateTodayAttendanceByClass(array|int $classYearIds, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    // Batched methods for N+1 prevention
    public function getBatchedLastAttendance(array $userIds): \Illuminate\Support\Collection;
    public function getBatchedAttendanceCounts(array $userIds, ?int $contextId): \Illuminate\Support\Collection;
    public function getBatchedConsecutiveAbsences(array $userIds, ?int $contextId, string $currentDate): \Illuminate\Support\Collection;
    public function getBatchedMonthAbsenceCounts(array $userIds, ?int $contextId, int $year, int $month): \Illuminate\Support\Collection;
}
