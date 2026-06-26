<?php

namespace App\Repositories;

use App\Contracts\AttendanceRepositoryInterface;
use App\Models\Attendance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AttendanceRepository implements AttendanceRepositoryInterface
{
    public function findById(int $id)
    {
        return Attendance::find($id);
    }

    public function create(array $data)
    {
        return Attendance::create($data);
    }

    public function update(int $id, array $data): bool
    {
        $attendance = $this->findById($id);
        if (!$attendance) {
            return false;
        }
        return $attendance->update($data);
    }

    public function delete(int $id): bool
    {
        $attendance = $this->findById($id);
        if (!$attendance) {
            return false;
        }
        return $attendance->delete();
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = Attendance::query()->with(['user', 'recorder', 'classe', 'event', 'attendanceContext']);

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['recorded_by'])) {
            $query->where('recorded_by', $filters['recorded_by']);
        }

        if (!empty($filters['event_id'])) {
            $query->where('event_id', $filters['event_id']);
        }

        if (!empty($filters['attendance_context_id'])) {
            $query->where('attendance_context_id', $filters['attendance_context_id']);
        }

        if (!empty($filters['class_ids']) && is_array($filters['class_ids'])) {
            $query->whereIn('class_year_id', $filters['class_ids']);
        } elseif (!empty($filters['class_id'])) {
            $query->where('class_year_id', $filters['class_id']);
        } elseif (!empty($filters['class_year_id'])) {
            $query->where('class_year_id', $filters['class_year_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('attended_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('attended_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return $query->latest('attended_at')->paginate($perPage);
    }

    public function hasAttendanceToday(int $userId, ?int $eventId = null, ?int $contextId = null): bool
    {
        $query = Attendance::where('user_id', $userId)
            ->whereDate('attended_at', today());

        if ($eventId) {
            $query->where('event_id', $eventId);
        }

        if ($contextId) {
            $query->where('attendance_context_id', $contextId);
        }

        return $query->exists();
    }

    public function getTodayAttendanceByClass(int $classYearId): Collection
    {
        return Attendance::where('class_year_id', $classYearId)
            ->whereDate('attended_at', today())
            ->with(['user', 'recorder', 'attendanceContext'])
            ->latest('attended_at')
            ->get();
    }

    public function getAttendanceByClassYear(int $classYearId, ?string $dateFrom = null, ?string $dateTo = null): Collection
    {
        $query = Attendance::where('class_year_id', $classYearId)
            ->with(['user', 'recorder', 'classe', 'event', 'attendanceContext']);

        if ($dateFrom) {
            $query->where('attended_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('attended_at', '<=', $dateTo);
        }

        return $query->latest('attended_at')->get();
    }

    public function getAttendanceCountByUser(int $userId): int
    {
        return Attendance::where('user_id', $userId)->count();
    }

    public function getAttendanceByDateRange(string $startDate, string $endDate): Collection
    {
        return Attendance::whereBetween('attended_at', [$startDate, $endDate])
            ->with(['user', 'recorder', 'classe', 'attendanceContext'])
            ->get();
    }

    public function getAttendanceByUserAndDateRange(int $userId, string $startDate, string $endDate): Collection
    {
        return Attendance::where('user_id', $userId)
            ->whereBetween('attended_at', [$startDate, $endDate])
            ->with(['user', 'recorder', 'classe', 'attendanceContext'])
            ->get();
    }

    public function getContextSummary(?string $dateFrom = null, ?string $dateTo = null, array|int|null $classYearIds = null): Collection
    {
        $query = Attendance::query()
            ->selectRaw('attendance_context_id')
            ->selectRaw('COUNT(*) as total_attendances')
            ->selectRaw('COUNT(DISTINCT user_id) as unique_members')
            ->with('attendanceContext')
            ->groupBy('attendance_context_id');

        if ($dateFrom) {
            $query->where('attended_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('attended_at', '<=', $dateTo);
        }

        if ($classYearIds !== null) {
            $classYearIds = is_array($classYearIds) ? $classYearIds : [$classYearIds];
            $query->whereIn('class_year_id', $classYearIds);
        }

        return $query->get();
    }

    public function getContextAnalytics(int $contextId, array|int|null $classYearId = null, ?int $servantId = null, ?string $dateFrom = null, ?string $dateTo = null, ?int $dayOfWeek = null): Collection
    {
        $query = Attendance::where('attendance_context_id', $contextId)
            ->with(['user', 'recorder', 'classe', 'attendanceContext']);

        if ($classYearId !== null) {
            $classYearIds = is_array($classYearId) ? $classYearId : [$classYearId];
            $query->whereIn('class_year_id', $classYearIds);
        }

        if ($servantId) {
            $query->where('recorded_by', $servantId);
        }

        if ($dateFrom) {
            $query->where('attended_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('attended_at', '<=', $dateTo);
        }

        return $query->latest('attended_at')->get();
    }

    public function getMembersByClassYear(int $classYearId): Collection
    {
        return \App\Models\User::byChurch()
            ->byRole(\App\Enums\UserRole::Member)
            ->where(function ($q) use ($classYearId) {
                $q->where('class_id', $classYearId)
                  ->orWhere('class_year_id', $classYearId);
            })
            ->active()
            ->with(['classe', 'servant'])
            ->get();
    }

    public function getAttendedUserIds(int $classYearId, ?int $eventId = null, ?int $contextId = null, ?string $date = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = Attendance::where('class_year_id', $classYearId);

        if ($eventId) {
            $query->where('event_id', $eventId);
        }

        if ($contextId) {
            $query->where('attendance_context_id', $contextId);
        }

        if ($date) {
            $query->whereDate('attended_at', $date);
        }

        if ($dateFrom) {
            $query->where('attended_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('attended_at', '<=', $dateTo);
        }

        return $query->pluck('user_id')->unique()->toArray();
    }

    public function getLastAttendanceByUser(int $userId): ?Attendance
    {
        return Attendance::where('user_id', $userId)
            ->latest('attended_at')
            ->first();
    }

    public function getAttendanceCountByUserAndContext(int $userId, int $contextId): int
    {
        return Attendance::where('user_id', $userId)
            ->where('attendance_context_id', $contextId)
            ->count();
    }

    public function getConsecutiveAbsences(int $userId, int $contextId, string $currentDate): int
    {
        $attendances = Attendance::where('user_id', $userId)
            ->where('attendance_context_id', $contextId)
            ->where('attended_at', '<', $currentDate)
            ->orderByDesc('attended_at')
            ->pluck('attended_at');

        if ($attendances->isEmpty()) {
            $totalSessions = Attendance::where('attendance_context_id', $contextId)
                ->where('attended_at', '<', $currentDate)
                ->distinct('attended_at')
                ->count('attended_at');
            return (int) $totalSessions;
        }

        $lastDate = $attendances->first();
        $distinctDates = Attendance::where('attendance_context_id', $contextId)
            ->where('attended_at', '>', $lastDate)
            ->where('attended_at', '<', $currentDate)
            ->distinct('attended_at')
            ->count('attended_at');

        return (int) $distinctDates;
    }

    public function getMonthAbsenceCount(int $userId, int $contextId, int $year, int $month): int
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $totalSessions = Attendance::where('attendance_context_id', $contextId)
            ->whereBetween('attended_at', [$start, $end])
            ->distinct('attended_at')
            ->count('attended_at');

        $userAttendances = Attendance::where('user_id', $userId)
            ->where('attendance_context_id', $contextId)
            ->whereBetween('attended_at', [$start, $end])
            ->distinct('attended_at')
            ->count('attended_at');

        return max(0, $totalSessions - $userAttendances);
    }

    public function paginateContextAnalytics(int $contextId, int $perPage = 15, array|int|null $classYearId = null, ?int $servantId = null, ?string $dateFrom = null, ?string $dateTo = null): LengthAwarePaginator
    {
        $query = Attendance::where('attendance_context_id', $contextId)
            ->with(['user', 'recorder', 'classe', 'attendanceContext']);

        if ($classYearId !== null) {
            $classYearIds = is_array($classYearId) ? $classYearId : [$classYearId];
            $query->whereIn('class_year_id', $classYearIds);
        }

        if ($servantId) {
            $query->where('recorded_by', $servantId);
        }

        if ($dateFrom) {
            $query->where('attended_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('attended_at', '<=', $dateTo);
        }

        return $query->latest('attended_at')->paginate($perPage);
    }

    public function paginateAttendanceByClassYear(int $classYearId, int $perPage = 15, ?string $dateFrom = null, ?string $dateTo = null): LengthAwarePaginator
    {
        $query = Attendance::where('class_year_id', $classYearId)
            ->with(['user', 'recorder', 'classe', 'event', 'attendanceContext']);

        if ($dateFrom) {
            $query->where('attended_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('attended_at', '<=', $dateTo);
        }

        return $query->latest('attended_at')->paginate($perPage);
    }

    public function paginateTodayAttendanceByClass(array|int $classYearIds, int $perPage = 15): LengthAwarePaginator
    {
        $classYearIds = is_array($classYearIds) ? $classYearIds : [$classYearIds];
        return Attendance::whereIn('class_year_id', $classYearIds)
            ->whereDate('attended_at', today())
            ->with(['user', 'recorder', 'attendanceContext'])
            ->latest('attended_at')
            ->paginate($perPage);
    }

    public function getTotalSessionsCount(?int $contextId, ?int $classYearId = null): int
    {
        $query = Attendance::where('attendance_context_id', $contextId);

        if ($classYearId) {
            $query->where('class_year_id', $classYearId);
        }

        return $query->distinct('attended_at')->count('attended_at');
    }

    public function getBatchedLastAttendance(array $userIds): Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        return Attendance::selectRaw('user_id, MAX(attended_at) as last_attended_at')
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
    }

    public function getBatchedAttendanceCounts(array $userIds, ?int $contextId): Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        return Attendance::selectRaw('user_id, COUNT(*) as count')
            ->whereIn('user_id', $userIds)
            ->where('attendance_context_id', $contextId)
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
    }

    public function getBatchedConsecutiveAbsences(array $userIds, ?int $contextId, string $currentDate): Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        $sessionDates = Attendance::where('attendance_context_id', $contextId)
            ->where('attended_at', '<', $currentDate)
            ->selectRaw('DISTINCT DATE(attended_at) as session_date')
            ->orderBy('session_date')
            ->pluck('session_date')
            ->toArray();

        $lastAttendances = Attendance::selectRaw('user_id, MAX(attended_at) as last_attended_at')
            ->whereIn('user_id', $userIds)
            ->where('attendance_context_id', $contextId)
            ->where('attended_at', '<', $currentDate)
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $results = collect();
        $totalSessionCount = count($sessionDates);

        foreach ($userIds as $uid) {
            $last = $lastAttendances->get($uid);
            if (!$last || !$last->last_attended_at) {
                $results->put($uid, (object) ['user_id' => $uid, 'consecutive_absences' => $totalSessionCount]);
                continue;
            }

            $lastDate = $last->last_attended_at instanceof \Carbon\Carbon
                ? $last->last_attended_at->toDateString()
                : date('Y-m-d', strtotime((string) $last->last_attended_at));

            $absences = 0;
            foreach ($sessionDates as $sd) {
                if ($sd > $lastDate) {
                    $absences++;
                }
            }

            $results->put($uid, (object) ['user_id' => $uid, 'consecutive_absences' => $absences]);
        }

        return $results;
    }

    public function getBatchedMonthAbsenceCounts(array $userIds, ?int $contextId, int $year, int $month): Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $totalSessions = Attendance::where('attendance_context_id', $contextId)
            ->whereBetween('attended_at', [$start, $end])
            ->distinct('attended_at')
            ->count('attended_at');

        $userCounts = Attendance::selectRaw('user_id, COUNT(DISTINCT DATE(attended_at)) as attended_days')
            ->whereIn('user_id', $userIds)
            ->where('attendance_context_id', $contextId)
            ->whereBetween('attended_at', [$start, $end])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $results = collect();
        foreach ($userIds as $uid) {
            $count = (int) ($userCounts->get($uid)?->attended_days ?? 0);
            $results->put($uid, (object) [
                'user_id' => $uid,
                'month_absences' => max(0, $totalSessions - $count),
            ]);
        }

        return $results;
    }
}
