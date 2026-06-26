<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Point;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $churchId = $user->church_id;

        $query = User::query();
        if ($churchId) {
            $query->where('church_id', $churchId);
        }

        $totalMembers = (clone $query)->where('role', UserRole::Member)->count();
        $activeMembers = (clone $query)->where('role', UserRole::Member)
            ->where('is_active', true)
            ->count();
        $totalServants = (clone $query)->whereIn('role', [UserRole::Servant])
            ->count();

        $totalAttendances = Attendance::whereHas('user', function ($q) use ($churchId) {
            if ($churchId) $q->where('church_id', $churchId);
        })->count();

        $totalPoints = Point::whereHas('user', function ($q) use ($churchId) {
            if ($churchId) $q->where('church_id', $churchId);
        })->sum('points');

        $totalMembersManaged = User::where('role', UserRole::Member)
            ->where(function ($q) use ($churchId) {
                if ($churchId) $q->where('church_id', $churchId);
            })
            ->whereNotNull('servant_id')
            ->count();

        return response()->json([
            'data' => [
                'total_members' => $totalMembers,
                'active_members' => $activeMembers,
                'total_attendances' => $totalAttendances,
                'total_points' => $totalPoints,
                'total_servants' => $totalServants,
                'total_members_managed' => $totalMembersManaged,
            ],
        ]);
    }
}
