<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface AttendanceRepositoryInterface
{
    public function findById(int $id): ?\App\Models\Attendance;

    /** @param array<string, mixed> $data */
    public function create(array $data): \App\Models\Attendance;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    /** @param array<string, mixed> $filters */
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Attendance> */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function hasAttendanceToday(int $userId, ?int $eventId = null, ?int $contextId = null): bool;
    public function getAttendanceCountByUser(int $userId): int;
    /** @return \Illuminate\Support\Collection<int, \App\Models\Attendance> */
    public function getTodayAttendanceByClass(int $classYearId): Collection;
    /** @return \Illuminate\Support\Collection<int, \App\Models\Attendance> */
    public function getAttendanceByClassYear(int $classYearId, ?string $dateFrom = null, ?string $dateTo = null): Collection;
    /** @return \Illuminate\Support\Collection<int, \App\Models\Attendance> */
    public function getAttendanceByDateRange(string $startDate, string $endDate): Collection;
    /** @return \Illuminate\Support\Collection<int, \App\Models\Attendance> */
    public function getAttendanceByUserAndDateRange(int $userId, string $startDate, string $endDate): Collection;
    /** @return \Illuminate\Support\Collection<int, \App\Models\Attendance> */
    public function getContextSummary(?string $dateFrom = null, ?string $dateTo = null, array|int|null $classYearIds = null): Collection;
    /** @return \Illuminate\Support\Collection<int, \App\Models\Attendance> */
    public function getContextAnalytics(int $contextId, array|int|null $classYearId = null, ?int $servantId = null, ?string $dateFrom = null, ?string $dateTo = null, ?int $dayOfWeek = null): Collection;
    /** @return \Illuminate\Support\Collection<int, \App\Models\User> */
    public function getMembersByClassYear(int $classYearId): Collection;
    /** @return array<int, int> */
    public function getAttendedUserIds(int $classYearId, ?int $eventId = null, ?int $contextId = null, ?string $date = null, ?string $dateFrom = null, ?string $dateTo = null): array;
    public function getLastAttendanceByUser(int $userId): ?\App\Models\Attendance;
    public function getAttendanceCountByUserAndContext(int $userId, int $contextId): int;
    public function getConsecutiveAbsences(int $userId, int $contextId, string $currentDate): int;
    public function getMonthAbsenceCount(int $userId, int $contextId, int $year, int $month): int;
    public function getTotalSessionsCount(?int $contextId, ?int $classYearId = null): int;

    // Paginated alternatives for large datasets
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Attendance> */
    public function paginateContextAnalytics(int $contextId, int $perPage = 15, array|int|null $classYearId = null, ?int $servantId = null, ?string $dateFrom = null, ?string $dateTo = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator;
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Attendance> */
    public function paginateAttendanceByClassYear(int $classYearId, int $perPage = 15, ?string $dateFrom = null, ?string $dateTo = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator;
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Attendance> */
    public function paginateTodayAttendanceByClass(array|int $classYearIds, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    // Batched methods for N+1 prevention
    /** @return \Illuminate\Support\Collection<int, object{last_attended_at: string|null}> */
    public function getBatchedLastAttendance(array $userIds): \Illuminate\Support\Collection;
    /** @return \Illuminate\Support\Collection<int, object{count: int}> */
    public function getBatchedAttendanceCounts(array $userIds, ?int $contextId): \Illuminate\Support\Collection;
    /** @return \Illuminate\Support\Collection<int, object{consecutive_absences: int}> */
    public function getBatchedConsecutiveAbsences(array $userIds, ?int $contextId, string $currentDate): \Illuminate\Support\Collection;
    /** @return \Illuminate\Support\Collection<int, object{month_absences: int}> */
    public function getBatchedMonthAbsenceCounts(array $userIds, ?int $contextId, int $year, int $month): \Illuminate\Support\Collection;
}
