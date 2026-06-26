<?php

namespace App\Services;

use App\Contracts\AttendanceContextServiceInterface;
use App\Contracts\AttendanceRepositoryInterface;
use App\Contracts\AttendanceServiceInterface;
use App\Contracts\PointServiceInterface;
use App\Enums\PointType;
use App\Events\AttendanceRecorded;
use App\Http\Resources\AttendanceResource;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService implements AttendanceServiceInterface
{
    private const POINTS_PER_ATTENDANCE = 10;

    public function __construct(
        private readonly AttendanceRepositoryInterface $attendanceRepository,
        private readonly PointServiceInterface $pointService,
        private readonly AttendanceContextServiceInterface $contextService,
        private readonly CacheService $cacheService,
    ) {}

    public function recordAttendanceByMemberId(string $memberId, int $recordedBy, int $contextId, ?int $eventId = null, string $method = 'id'): array
    {
        $member = User::byChurch()
            ->byMemberId($memberId)
            ->first();

        if (!$member) {
            throw ValidationException::withMessages([
                'member_id' => ['Member not found.'],
            ]);
        }

        return $this->processAttendance($member, $recordedBy, $contextId, $eventId, $method, 'member_id');
    }

    public function recordAttendance(string $qrToken, int $recordedBy, int $contextId, ?int $eventId = null, string $method = 'qr'): array
    {
        $member = User::byChurch()
            ->byAttendanceQrToken($qrToken)
            ->first();

        if (!$member) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or unrecognized attendance token.'],
            ]);
        }

        return $this->processAttendance($member, $recordedBy, $contextId, $eventId, $method, 'token');
    }

    private function processAttendance(User $member, int $recordedBy, int $contextId, ?int $eventId, string $method, string $errorField): array
    {
        if (!$member->is_active) {
            throw ValidationException::withMessages([
                $errorField => ['This member account is inactive.'],
            ]);
        }

        $context = \App\Models\AttendanceContext::withoutGlobalScope(\App\Models\Scopes\ChurchScope::class)
            ->where('id', $contextId)
            ->where('is_active', true)
            ->first();

        if (!$context) {
            throw ValidationException::withMessages([
                'attendance_context_id' => ['Invalid or inactive attendance context.'],
            ]);
        }

        if ($eventId) {
            $event = Event::find($eventId);
            if (!$event) {
                throw ValidationException::withMessages([
                    'event_id' => ['Event not found.'],
                ]);
            }
        }

        try {
            return DB::transaction(function () use ($member, $recordedBy, $eventId, $contextId, $context, $method) {
                User::byChurch()
                    ->where('id', $member->id)
                    ->lockForUpdate()
                    ->first();

                if ($this->attendanceRepository->hasAttendanceToday($member->id, $eventId, $contextId)) {
                    $contextName = $context->name ?? 'this session';
                    throw ValidationException::withMessages([
                        'attendance' => [__('attendance.duplicate_context', ['context' => $contextName])],
                    ]);
                }

                $attendance = $this->attendanceRepository->create([
                    'user_id' => $member->id,
                    'recorded_by' => $recordedBy,
                    'class_year_id' => $member->class_year_id ?? $member->class_id,
                    'event_id' => $eventId,
                    'attendance_context_id' => $contextId,
                    'method' => $method,
                    'attended_at' => now(),
                    'points_earned' => self::POINTS_PER_ATTENDANCE,
                ]);

                $this->pointService->addPoints(
                    userId: $member->id,
                    points: self::POINTS_PER_ATTENDANCE,
                    type: PointType::Attendance->value,
                    description: $eventId ? __('attendance.points_earned_event') : __('attendance.points_earned'),
                    referenceType: 'attendance',
                    referenceId: $attendance->id,
                );

                AttendanceRecorded::dispatch($attendance);

                return [
                    'attendance' => $attendance->load(['user', 'recorder', 'classe', 'event', 'attendanceContext']),
                    'points_earned' => self::POINTS_PER_ATTENDANCE,
                ];
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages([
                    'attendance' => [__('attendance.duplicate_context', ['context' => $context->name ?? 'this session'])],
                ]);
            }
            throw $e;
        }
    }

    public function getAttendanceHistory(int $userId, int $perPage = 15): array
    {
        $paginator = $this->attendanceRepository->paginate($perPage, ['user_id' => $userId]);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function getTodayAttendance(array|int|null $classYearIds = null, int $perPage = 15): array
    {
        $churchId = auth()->user()?->church_id;

        if ($classYearIds !== null) {
            $classYearIds = is_array($classYearIds) ? $classYearIds : [$classYearIds];
            return $this->cacheService->rememberAttendanceToday($churchId, null, function () use ($classYearIds, $perPage) {
                $paginator = $this->attendanceRepository->paginateTodayAttendanceByClass($classYearIds, $perPage);
                return [
                    'data' => $paginator->items(),
                    'count' => $paginator->total(),
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                    ],
                ];
            });
        }

        return $this->cacheService->rememberAttendanceToday($churchId, null, function () {
            $attendances = $this->attendanceRepository->getAttendanceByDateRange(
                today()->startOfDay()->toDateTimeString(),
                today()->endOfDay()->toDateTimeString()
            );

            return [
                'data' => $attendances,
                'count' => $attendances->count(),
            ];
        });
    }

    public function getAttendanceByClass(int $classYearId, ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 15): array
    {
        $paginator = $this->attendanceRepository->paginateAttendanceByClassYear(
            $classYearId,
            $perPage,
            $dateFrom,
            $dateTo
        );

        return [
            'data' => $paginator->items(),
            'count' => $paginator->total(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function getFilteredAttendances(array $filters, int $perPage = 15): array
    {
        $paginator = $this->attendanceRepository->paginate($perPage, $filters);

        return [
            'data' => AttendanceResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function getAttendanceStats(int $userId = null): array
    {
        if (!$userId) {
            return ['total_attendances' => 0, 'this_month' => 0];
        }

        $user = User::find($userId);
        return $this->cacheService->rememberAttendanceStats($user?->church_id, $userId, function () use ($userId) {
            $total = $this->attendanceRepository->getAttendanceCountByUser($userId);
            $thisMonth = $this->attendanceRepository->getAttendanceByUserAndDateRange(
                $userId,
                now()->startOfMonth()->toDateTimeString(),
                now()->endOfMonth()->toDateTimeString()
            )->count();

            return [
                'total_attendances' => $total,
                'this_month' => $thisMonth,
            ];
        });
    }

    public function getAbsentMembers(int $classYearId, ?int $eventId = null, ?int $contextId = null, ?string $date = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $members = $this->attendanceRepository->getMembersByClassYear($classYearId);
        $attendedUserIds = $this->attendanceRepository->getAttendedUserIds(
            $classYearId, $eventId, $contextId, $date, $dateFrom, $dateTo
        );

        $attendedSet = array_flip($attendedUserIds);

        $contextIdForStats = $contextId ?? $this->contextService->getDefaultId();

        $totalMembers = $members->count();
        $presentCount = count($attendedUserIds);
        $absentCount = $totalMembers - $presentCount;

        $absentMembers = $members->reject(fn($m) => isset($attendedSet[$m->id]))->values();
        $absentUserIds = $absentMembers->pluck('id')->toArray();

        $totalSessions = $this->attendanceRepository->getTotalSessionsCount($contextIdForStats, $classYearId);

        // Batched queries — 4 queries total instead of N*5
        $lastAttendances = $this->attendanceRepository->getBatchedLastAttendance($absentUserIds);
        $attendanceCounts = $this->attendanceRepository->getBatchedAttendanceCounts($absentUserIds, $contextIdForStats);
        $now = $date ?: now()->toDateString();
        $consecutiveAbsences = $this->attendanceRepository->getBatchedConsecutiveAbsences($absentUserIds, $contextIdForStats, $now);
        $monthAbsences = $this->attendanceRepository->getBatchedMonthAbsenceCounts(
            $absentUserIds, $contextIdForStats, (int) now()->year, (int) now()->month
        );

        $memberDetails = $absentMembers->map(function ($member) use (
            $lastAttendances, $attendanceCounts, $totalSessions,
            $consecutiveAbsences, $monthAbsences
        ) {
            $lastAttendanceDate = $lastAttendances->get($member->id)?->last_attended_at;
            $attendanceCount = (int) ($attendanceCounts->get($member->id)?->count ?? 0);
            $attendancePercentage = $totalSessions > 0
                ? round(($attendanceCount / $totalSessions) * 100, 1)
                : 0;

            return [
                'id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
                'class_year' => $member->classe ? [
                    'id' => $member->classe->id,
                    'name' => $member->classe->name,
                ] : null,
                'last_attendance_date' => $lastAttendanceDate
                    ? (is_string($lastAttendanceDate) ? $lastAttendanceDate : $lastAttendanceDate->toISOString())
                    : null,
                'attendance_count' => $attendanceCount,
                'total_sessions' => $totalSessions,
                'attendance_percentage' => $attendancePercentage,
                'consecutive_absences' => (int) ($consecutiveAbsences->get($member->id)?->consecutive_absences ?? 0),
                'month_absences' => (int) ($monthAbsences->get($member->id)?->month_absences ?? 0),
            ];
        });

        $absentMembersSorted = $memberDetails->sortByDesc('consecutive_absences')->values();

        return [
            'summary' => [
                'total_members' => $totalMembers,
                'present_count' => $presentCount,
                'absent_count' => $absentCount,
                'context_id' => $contextIdForStats,
            ],
            'absent_members' => $absentMembersSorted->toArray(),
        ];
    }

    public function getContextSummary(?string $dateFrom = null, ?string $dateTo = null, array|int|null $classYearIds = null): array
    {
        $churchId = auth()->user()?->church_id;
        $classYearId = is_array($classYearIds) ? null : $classYearIds;

        return $this->cacheService->rememberContextSummary($churchId, $dateFrom, $dateTo, $classYearId, function () use ($dateFrom, $dateTo, $classYearIds) {
            $rows = $this->attendanceRepository->getContextSummary($dateFrom, $dateTo, $classYearIds);

            $summary = $rows->map(fn($row) => [
                'context' => $row->attendanceContext ? [
                    'id' => $row->attendanceContext->id,
                    'name' => $row->attendanceContext->name,
                    'slug' => $row->attendanceContext->slug,
                ] : null,
                'total_attendances' => (int) $row->total_attendances,
                'unique_members' => (int) $row->unique_members,
            ]);

            return [
                'data' => $summary,
            ];
        });
    }

    public function getContextAnalytics(int $contextId, array|int|null $classYearId = null, ?int $servantId = null, ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 15): array
    {
        $paginator = $this->attendanceRepository->paginateContextAnalytics(
            $contextId,
            $perPage,
            $classYearId,
            $servantId,
            $dateFrom,
            $dateTo
        );

        $records = $paginator->items();
        $uniqueMemberIds = collect($records)->pluck('user_id')->unique();
        $classCounts = collect($records)->groupBy('class_year_id')->map(fn($group) => [
            'class_year_id' => $group->first()->classe?->id,
            'class_name' => $group->first()->classe?->name ?? 'Unknown',
            'count' => $group->count(),
        ])->values();

        $mostActiveClass = $classCounts->sortByDesc('count')->first();

        return [
            'data' => [
                'summary' => [
                    'total_attendances' => $paginator->total(),
                    'unique_members' => $uniqueMemberIds->count(),
                    'most_active_class' => $mostActiveClass,
                    'class_distribution' => $classCounts,
                ],
                'records' => AttendanceResource::collection($records),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ];
    }
}
