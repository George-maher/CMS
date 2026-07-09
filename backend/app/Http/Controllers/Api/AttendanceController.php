<?php

namespace App\Http\Controllers\Api;

use App\Contracts\AttendanceServiceInterface;
use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\UserResource;
use App\Models\AttendanceContext;
use App\Models\QRInvite;
use App\Models\Scopes\ChurchScope;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceServiceInterface $attendanceService,
    ) {}

    public function recordByMemberId(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'member_id' => ['required', 'string', 'max:20'],
            'event_id' => ['sometimes', 'integer', 'exists:events,id'],
            'attendance_context_id' => ['required', 'integer', 'exists:attendance_contexts,id'],
            'method' => ['sometimes', 'string', 'in:qr,token,id'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var int $recordedBy */
        $recordedBy = $user->id;
        /** @var string $memberId */
        $memberId = $validated['member_id'];
        /** @var int $contextId */
        $contextId = $validated['attendance_context_id'];
        /** @var string $method */
        $method = $validated['method'] ?? 'id';
        $result = $this->attendanceService->recordAttendanceByMemberId(
            memberId: $memberId,
            recordedBy: $recordedBy,
            eventId: $request->has('event_id') ? $request->integer('event_id') : null,
            contextId: $contextId,
            method: $method,
        );

        return response()->json([
            'message' => 'Attendance recorded successfully.',
            'data' => [
                'attendance' => new AttendanceResource($result['attendance']),
                'points_earned' => $result['points_earned'],
            ],
        ], 201);
    }

    public function record(RecordAttendanceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $recordedBy */
        $recordedBy = $user->id;
        $qrToken = $request->str('qr_token', '');
        $eventId = $request->integer('event_id') ?: null;
        $contextId = $request->integer('attendance_context_id');
        $method = (string) $request->str('method', 'qr');

        $result = $this->attendanceService->recordAttendance(
            qrToken: $qrToken,
            recordedBy: $recordedBy,
            eventId: $eventId,
            contextId: $contextId,
            method: $method,
        );

        return response()->json([
            'message' => 'Attendance recorded successfully.',
            'data' => [
                'attendance' => new AttendanceResource($result['attendance']),
                'points_earned' => $result['points_earned'],
            ],
        ], 201);
    }

    public function contextSummary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<int, int>|null $classYearIds */
        $classYearIds = $user->role === UserRole::Servant
            ? $user->getServantClassIds()
            : ($request->input('class_id') ? [$request->integer('class_id')] : null);

        /** @var string|null $dateFrom */
        $dateFrom = $request->input('date_from');
        /** @var string|null $dateTo */
        $dateTo = $request->input('date_to');

        $result = $this->attendanceService->getContextSummary(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            classYearIds: $classYearIds,
        );

        return response()->json($result);
    }

    public function contextDetails(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'context_id' => ['required', 'integer', 'exists:attendance_contexts,id'],
            'class_id' => ['sometimes', 'integer', 'exists:classes,id'],
            'servant_id' => ['sometimes', 'integer', 'exists:users,id'],
            'date' => ['sometimes', 'date'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var int|null $classYearId */
        $classYearId = $validated['class_id'] ?? null;

        // Servants: silently fix class_id instead of rejecting
        if ($user->role === UserRole::Servant) {
            /** @var array<int, int>|null $servantClassIds */
            $servantClassIds = $user->getServantClassIds();
            if ($classYearId !== null && ! in_array($classYearId, (array) $servantClassIds)) {
                $classYearId = null; // ignore unauthorized value, fall through to enforce servant's classes
            }
            if ($classYearId === null) {
                $classYearId = $servantClassIds;
            }
        }

        /** @var string|null $dateFrom */
        $dateFrom = $validated['date'] ?? $validated['date_from'] ?? null;
        /** @var string|null $dateTo */
        $dateTo = $validated['date'] ?? $validated['date_to'] ?? null;

        /** @var int $contextId */
        $contextId = $validated['context_id'];
        /** @var int|null $servantId */
        $servantId = $validated['servant_id'] ?? null;

        $result = $this->attendanceService->getContextAnalytics(
            contextId: $contextId,
            classYearId: $classYearId,
            servantId: $servantId,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            perPage: $request->integer('per_page', 15),
        );

        return response()->json($result);
    }

    public function lookupByMemberId(Request $request, string $memberId): JsonResponse
    {
        $member = User::byChurch()->byMemberId($memberId)->first();

        if (! $member) {
            throw ValidationException::withMessages([
                'member_id' => ['Member not found.'],
            ]);
        }

        return response()->json([
            'data' => [
                'member' => new UserResource($member),
            ],
        ]);
    }

    public function lookupByToken(Request $request, string $qrToken): JsonResponse
    {
        $member = User::byChurch()->byAttendanceQrToken($qrToken)->first();

        $attendanceContextId = null;

        // If no user found, try invite token lookup (supports attendance_qr invite URLs)
        if (! $member) {
            $inviteToken = $qrToken;
            // Extract token from URL pattern: {base}/qr/validate/{token}
            if (preg_match('#/qr/validate/([A-Za-z0-9]+)$#', $qrToken, $matches)) {
                $inviteToken = $matches[1];
            }

            $invite = QRInvite::byChurch()
                ->where('token', $inviteToken)
                ->where('type', QRInviteType::AttendanceQR)
                ->valid()
                ->first();

            if ($invite && $invite->attendance_context_id) {
                $attendanceContextId = $invite->attendance_context_id;
            }
        }

        if (! $member && ! $attendanceContextId) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        $responseData = [];

        if ($member) {
            $responseData['member'] = new UserResource($member);
        }

        if ($attendanceContextId) {
            $responseData['attendance_context_id'] = $attendanceContextId;
            $ctx = AttendanceContext::withoutGlobalScope(ChurchScope::class)->find($attendanceContextId);
            $responseData['attendance_context'] = $ctx ? [
                'id' => $ctx->id,
                'name' => $ctx->name,
                'slug' => $ctx->slug,
            ] : null;
        }

        return response()->json([
            'data' => $responseData,
        ]);
    }

    public function history(Request $request, ?int $userId = null): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $id */
        $id = $userId ?? (int) $user->id;

        if ($user->role === UserRole::Servant && $id !== (int) $user->id) {
            $member = User::byChurch()->find($id);
            /** @var array<int, int>|null $servantClassIds */
            $servantClassIds = $user->getServantClassIds();
            if (! $member || ! in_array($member->class_id, (array) $servantClassIds)) {
                $id = (int) $user->id;
            }
        } elseif ($user->role === UserRole::Member) {
            $id = (int) $user->id;
        }

        /** @var int $perPage */
        $perPage = $request->integer('per_page', 15);
        $result = $this->attendanceService->getAttendanceHistory(
            userId: $id,
            perPage: $perPage,
        );

        return response()->json([
            'data' => AttendanceResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function byClass(Request $request, int $classYearId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->role === UserRole::Servant) {
            /** @var array<int, int>|null $servantClassIds */
            $servantClassIds = $user->getServantClassIds();
            if (! in_array($classYearId, (array) $servantClassIds)) {
                $classYearId = ($servantClassIds[0] ?? $classYearId);
            }
        }

        /** @var string|null $dateFrom */
        $dateFrom = $request->input('date_from');
        /** @var string|null $dateTo */
        $dateTo = $request->input('date_to');
        /** @var int $perPage */
        $perPage = $request->integer('per_page', 15);
        $result = $this->attendanceService->getAttendanceByClass(
            classYearId: $classYearId,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            perPage: $perPage,
        );

        return response()->json([
            'data' => AttendanceResource::collection($result['data']),
            'count' => $result['count'],
            'meta' => $result['meta'] ?? null,
        ]);
    }

    public function today(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<int, int>|null $classYearIds */
        $classYearIds = $user->role === UserRole::Servant ? $user->getServantClassIds() : null;

        $result = $this->attendanceService->getTodayAttendance(
            classYearIds: $classYearIds,
            perPage: $request->integer('per_page', 15),
        );

        return response()->json([
            'data' => AttendanceResource::collection($result['data']),
            'count' => $result['count'],
            'meta' => $result['meta'] ?? null,
        ]);
    }

    public function filtered(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'attendance_context_id' => ['sometimes', 'integer', 'exists:attendance_contexts,id'],
            'class_id' => ['sometimes', 'integer', 'exists:classes,id'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'search' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Servants: silently fix class_id instead of rejecting
        if ($user->role === UserRole::Servant) {
            /** @var array<int, int>|null $servantClassIds */
            $servantClassIds = $user->getServantClassIds();
            if (empty($servantClassIds)) {
                return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0]]);
            }
            /** @var int|null $validatedClassId */
            $validatedClassId = $validated['class_id'] ?? null;
            if ($validatedClassId !== null && ! in_array($validatedClassId, $servantClassIds)) {
                unset($validated['class_id']);
            }
            $validated['class_ids'] = $servantClassIds;
        }

        $result = $this->attendanceService->getFilteredAttendances(
            filters: $validated,
            perPage: $request->integer('per_page', 15),
        );

        return response()->json($result);
    }

    public function absentMembers(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $request->has('class_id')) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => ['class_id' => ['The class id field is required.']],
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $classYearId = $request->integer('class_id');
        $eventId = $request->has('event_id') ? $request->integer('event_id') : null;
        $contextId = $request->has('context_id') ? $request->integer('context_id') : null;
        /** @var string|null $date */
        $date = $request->input('date');
        /** @var string|null $dateFrom */
        $dateFrom = $request->input('date_from');
        /** @var string|null $dateTo */
        $dateTo = $request->input('date_to');

        // Servants: silently override to their assigned class — never error, always enforce
        if ($user->role === UserRole::Servant) {
            /** @var array<int, int>|null $servantClassIds */
            $servantClassIds = $user->getServantClassIds();
            if (! empty($servantClassIds) && ($classYearId === 0 || ! in_array($classYearId, $servantClassIds))) {
                $classYearId = $servantClassIds[0];
            } elseif (empty($servantClassIds)) {
                return response()->json(['data' => ['summary' => ['total_members' => 0, 'present_count' => 0, 'absent_count' => 0], 'absent_members' => []]]);
            }
        }

        $result = $this->attendanceService->getAbsentMembers(
            classYearId: $classYearId,
            eventId: $eventId,
            contextId: $contextId,
            date: $date,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );

        return response()->json(['data' => $result]);
    }

    public function stats(Request $request, ?int $userId = null): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var int $id */
        $id = $userId ?? (int) $user->id;

        if ($user->role === UserRole::Servant && $id !== (int) $user->id) {
            $member = User::byChurch()->find($id);
            /** @var array<int, int>|null $servantClassIds */
            $servantClassIds = $user->getServantClassIds();
            if (! $member || ! in_array($member->class_id, (array) $servantClassIds)) {
                $id = (int) $user->id;
            }
        } elseif ($user->role === UserRole::Member) {
            $id = (int) $user->id;
        }

        $result = $this->attendanceService->getAttendanceStats($id);

        return response()->json([
            'data' => $result,
        ]);
    }
}
