<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Point;
use App\Models\User;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CacheService $cacheService,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $churchId = $user->church_id;

        $data = $this->cacheService->rememberDashboardStats($churchId ?: 0, function () use ($churchId) {
            $query = User::query();
            if ($churchId) {
                $query->where('church_id', $churchId);
            }

            $memberQuery = (clone $query)->where('role', UserRole::Member);
            $totalMembers = (clone $memberQuery)->count();
            $activeMembers = (clone $memberQuery)->where('is_active', true)->count();
            $totalMembersManaged = (clone $memberQuery)->whereNotNull('servant_id')->count();

            $totalServants = (clone $query)->where('role', UserRole::Servant)->count();

            $totalAttendances = Attendance::whereHas('user', function ($q) use ($churchId) {
                if ($churchId) {
                    $q->where('church_id', $churchId);
                }
            })->count();

            $totalPoints = Point::whereHas('user', function ($q) use ($churchId) {
                if ($churchId) {
                    $q->where('church_id', $churchId);
                }
            })->sum('points');

            return [
                'total_members' => $totalMembers,
                'active_members' => $activeMembers,
                'total_servants' => $totalServants,
                'total_attendances' => $totalAttendances,
                'total_points' => $totalPoints,
                'total_members_managed' => $totalMembersManaged,
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }
}
