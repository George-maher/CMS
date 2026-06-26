<?php

namespace App\Http\Controllers\Api;

use App\Contracts\AttendanceServiceInterface;
use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\UserResource;
use App\Models\QRInvite;
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
        $validated = $request->validate([
            'member_id' => ['required', 'string', 'max:20'],
            'event_id' => ['sometimes', 'integer', 'exists:events,id'],
            'attendance_context_id' => ['required', 'integer', 'exists:attendance_contexts,id'],
            'method' => ['sometimes', 'string', 'in:qr,token,id'],
        ]);

        $result = $this->attendanceService->recordAttendanceByMemberId(
            memberId: $validated['member_id'],
            recordedBy: $request->user()->id,
            eventId: $validated['event_id'] ?? null,
            contextId: $validated['attendance_context_id'],
            method: $validated['method'] ?? 'id',
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
        $result = $this->attendanceService->recordAttendance(
            qrToken: $request->input('qr_token'),
            recordedBy: $request->user()->id,
            eventId: $request->input('event_id'),
            contextId: $request->input('attendance_context_id'),
            method: $request->input('method', 'qr'),
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
        $user = $request->user();
        $classYearIds = $user->role === UserRole::Servant
            ? $user->getServantClassIds()
            : ($request->input('class_id') ? [(int) $request->input('class_id')] : null);

        $result = $this->attendanceService->getContextSummary(
            dateFrom: $request->input('date_from'),
            dateTo: $request->input('date_to'),
            classYearIds: $classYearIds,
        );

        return response()->json($result);
    }

    public function contextDetails(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'context_id' => ['required', 'integer', 'exists:attendance_contexts,id'],
            'class_id' => ['sometimes', 'integer', 'exists:classes,id'],
            'servant_id' => ['sometimes', 'integer', 'exists:users,id'],
            'date' => ['sometimes', 'date'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
        ]);

        $user = $request->user();
        $classYearId = $validated['class_id'] ?? null;

        // Servants: silently fix class_id instead of rejecting
        if ($user->role === UserRole::Servant) {
            $servantClassIds = $user->getServantClassIds();
            if ($classYearId !== null && !in_array($classYearId, $servantClassIds)) {
                $classYearId = null; // ignore unauthorized value, fall through to enforce servant's classes
            }
            if ($classYearId === null) {
                $classYearId = $servantClassIds;
            }
        }

        $dateFrom = $validated['date'] ?? $validated['date_from'] ?? null;
        $dateTo = $validated['date'] ?? $validated['date_to'] ?? null;

        $result = $this->attendanceService->getContextAnalytics(
            contextId: $validated['context_id'],
            classYearId: $classYearId,
            servantId: $validated['servant_id'] ?? null,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            perPage: (int) ($request->input('per_page', 15)),
        );

        return response()->json($result);
    }

    public function lookupByMemberId(Request $request, string $memberId): JsonResponse
    {
        $member = User::byChurch()->byMemberId($memberId)->first();

        if (!$member) {
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
        if (!$member) {
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

        if (!$member && !$attendanceContextId) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        $responseData = [];

        if ($member) {
            $responseData['member'] = new UserResource($member);
        }

        if ($attendanceContextId) {
            $responseData['attendance_context_id'] = $attendanceContextId;
            $ctx = \App\Models\AttendanceContext::withoutGlobalScope(\App\Models\Scopes\ChurchScope::class)->find($attendanceContextId);
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

    public function history(Request $request, int $userId = null): JsonResponse
    {
        $user = $request->user();
        $id = $userId ?? $user->id;

        if ($user->role === UserRole::Servant && $id !== $user->id) {
            $member = User::byChurch()->find($id);
            $servantClassIds = $user->getServantClassIds();
            if (!$member || !in_array($member->class_id, $servantClassIds)) {
                $id = $user->id; // silently fall back to own history
            }
        } elseif ($user->role === UserRole::Member) {
            $id = $user->id;
        }

        $result = $this->attendanceService->getAttendanceHistory(
            userId: $id,
            perPage: $request->input('per_page', 15)
        );

        return response()->json([
            'data' => AttendanceResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function byClass(Request $request, int $classYearId): JsonResponse
    {
        $user = $request->user();
        if ($user->role === UserRole::Servant) {
            $servantClassIds = $user->getServantClassIds();
            if (!in_array($classYearId, $servantClassIds)) {
                $classYearId = $servantClassIds[0] ?? $classYearId;
            }
        }

        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $result = $this->attendanceService->getAttendanceByClass(
            classYearId: $classYearId, // accepts class_id from classes table (mapped via class_year_id column)
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            perPage: (int) ($request->input('per_page', 15)),
        );

        return response()->json([
            'data' => AttendanceResource::collection($result['data']),
            'count' => $result['count'],
            'meta' => $result['meta'] ?? null,
        ]);
    }

    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $classYearIds = $user->role === UserRole::Servant ? $user->getServantClassIds() : null;

        $result = $this->attendanceService->getTodayAttendance(
            classYearIds: $classYearIds,
            perPage: (int) ($request->input('per_page', 15)),
        );

        return response()->json([
            'data' => AttendanceResource::collection($result['data']),
            'count' => $result['count'],
            'meta' => $result['meta'] ?? null,
        ]);
    }

    public function filtered(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attendance_context_id' => ['sometimes', 'integer', 'exists:attendance_contexts,id'],
            'class_id' => ['sometimes', 'integer', 'exists:classes,id'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'search' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        // Servants: silently fix class_id instead of rejecting
        if ($user->role === UserRole::Servant) {
            $servantClassIds = $user->getServantClassIds();
            if (empty($servantClassIds)) {
                return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0]]);
            }
            if (!empty($validated['class_id']) && !in_array((int) $validated['class_id'], $servantClassIds)) {
                unset($validated['class_id']);
            }
            $validated['class_ids'] = $servantClassIds;
        }

        $result = $this->attendanceService->getFilteredAttendances(
            filters: $validated,
            perPage: (int) ($request->input('per_page', 15)),
        );

        return response()->json($result);
    }

    public function absentMembers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'event_id' => ['sometimes', 'integer', 'exists:events,id'],
            'context_id' => ['sometimes', 'integer', 'exists:attendance_contexts,id'],
            'date' => ['sometimes', 'date'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
        ]);

        $user = $request->user();

        // Servants: silently override to their assigned class — never error, always enforce
        if ($user->role === UserRole::Servant) {
            $servantClassIds = $user->getServantClassIds();
            if (!empty($servantClassIds) && (empty($validated['class_id']) || !in_array((int) $validated['class_id'], $servantClassIds))) {
                $validated['class_id'] = $servantClassIds[0];
            } elseif (empty($servantClassIds)) {
                return response()->json(['data' => ['summary' => ['total_members' => 0, 'present_count' => 0, 'absent_count' => 0], 'absent_members' => []]]);
            }
        }

        $result = $this->attendanceService->getAbsentMembers(
            classYearId: (int) $validated['class_id'],
            eventId: isset($validated['event_id']) ? (int) $validated['event_id'] : null,
            contextId: isset($validated['context_id']) ? (int) $validated['context_id'] : null,
            date: $validated['date'] ?? null,
            dateFrom: $validated['date_from'] ?? null,
            dateTo: $validated['date_to'] ?? null,
        );

        return response()->json(['data' => $result]);
    }

    public function stats(Request $request, int $userId = null): JsonResponse
    {
        $user = $request->user();
        $id = $userId ?? $user->id;

        if ($user->role === UserRole::Servant && $id !== $user->id) {
            $member = User::byChurch()->find($id);
            $servantClassIds = $user->getServantClassIds();
            if (!$member || !in_array($member->class_id, $servantClassIds)) {
                $id = $user->id; // silently fall back to own stats
            }
        } elseif ($user->role === UserRole::Member) {
            $id = $user->id;
        }

        $result = $this->attendanceService->getAttendanceStats($id);

        return response()->json([
            'data' => $result,
        ]);
    }
}
