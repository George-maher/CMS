<?php

namespace App\Services;

use App\Contracts\MemberProfileServiceInterface;
use App\Models\Attendance;
use App\Models\DailySpiritualRecord;
use App\Models\User;
use Illuminate\Support\Carbon;

class MemberProfileService implements MemberProfileServiceInterface
{
    /**
     * Get comprehensive member profile data including attendance and spiritual summary.
     *
     * @return array<string, mixed>
     */
    public function getProfile(int $memberId, int $requesterId): array
    {
        $member = User::with(['classe.stage', 'servant', 'church'])->find($memberId);

        if (! $member) {
            return ['found' => false];
        }

        $requester = User::find($requesterId);
        if (! $requester) {
            return ['found' => false];
        }

        // Authorization check
        if (! $this->canViewProfile($requester, $member)) {
            return ['found' => true, 'authorized' => false];
        }

        $attendanceSummary = $this->getAttendanceSummary($memberId);
        $spiritualSummary = $this->getSpiritualSummary($memberId);

        return [
            'found' => true,
            'authorized' => true,
            'member' => $member,
            'attendance' => $attendanceSummary,
            'spiritual' => $spiritualSummary,
        ];
    }

    /**
     * Get attendance summary for a member.
     *
     * @return array{total_attended: int, total_sessions: int, absences: int, percentage: float, this_month: int, this_month_total: int}
     */
    public function getAttendanceSummary(int $memberId): array
    {
        $totalAttended = Attendance::where('user_id', $memberId)->count();

        $now = Carbon::now();
        $thisMonth = Attendance::where('user_id', $memberId)
            ->whereYear('attended_at', $now->year)
            ->whereMonth('attended_at', $now->month)
            ->count();

        // Total sessions = total attendance records for the user's class
        // (We count total attendance records across all members in same class as a proxy for total sessions)
        $member = User::find($memberId);
        $totalSessions = 0;
        if ($member && $member->class_id) {
            $sessionResult = Attendance::where('class_year_id', $member->class_id)
                ->selectRaw('COUNT(DISTINCT DATE(attended_at)) as count')
                ->first();
            $totalSessions = $sessionResult !== null ? (int) $sessionResult->count : 0;
        }

        $absences = max(0, $totalSessions - $totalAttended);
        $percentage = $totalSessions > 0 ? round(($totalAttended / $totalSessions) * 100, 1) : 0.0;

        // This month total sessions for the class
        $thisMonthTotal = 0;
        if ($member && $member->class_id) {
            $monthSessionResult = Attendance::where('class_year_id', $member->class_id)
                ->whereYear('attended_at', $now->year)
                ->whereMonth('attended_at', $now->month)
                ->selectRaw('COUNT(DISTINCT DATE(attended_at)) as count')
                ->first();
            $thisMonthTotal = $monthSessionResult !== null ? (int) $monthSessionResult->count : 0;
        }

        return [
            'total_attended' => $totalAttended,
            'total_sessions' => $totalSessions,
            'absences' => (int) $absences,
            'percentage' => $percentage,
            'this_month' => $thisMonth,
            'this_month_total' => $thisMonthTotal,
        ];
    }

    /**
     * Get spiritual records summary for a member.
     *
     * @return array{total_records: int, mass_count: int, confession_count: int, communion_count: int, this_month_mass: int, this_month_confession: int, this_month_communion: int}
     */
    public function getSpiritualSummary(int $memberId): array
    {
        $totalRecords = DailySpiritualRecord::where('user_id', $memberId)->count();
        $massCount = DailySpiritualRecord::where('user_id', $memberId)->where('attended_mass', true)->count();
        $confessionCount = DailySpiritualRecord::where('user_id', $memberId)->where('confessed', true)->count();
        $communionCount = DailySpiritualRecord::where('user_id', $memberId)->where('received_communion', true)->count();

        $now = Carbon::now();
        $thisMonthMass = DailySpiritualRecord::where('user_id', $memberId)
            ->where('attended_mass', true)
            ->whereYear('activity_date', $now->year)
            ->whereMonth('activity_date', $now->month)
            ->count();
        $thisMonthConfession = DailySpiritualRecord::where('user_id', $memberId)
            ->where('confessed', true)
            ->whereYear('activity_date', $now->year)
            ->whereMonth('activity_date', $now->month)
            ->count();
        $thisMonthCommunion = DailySpiritualRecord::where('user_id', $memberId)
            ->where('received_communion', true)
            ->whereYear('activity_date', $now->year)
            ->whereMonth('activity_date', $now->month)
            ->count();

        return [
            'total_records' => $totalRecords,
            'mass_count' => $massCount,
            'confession_count' => $confessionCount,
            'communion_count' => $communionCount,
            'this_month_mass' => $thisMonthMass,
            'this_month_confession' => $thisMonthConfession,
            'this_month_communion' => $thisMonthCommunion,
        ];
    }

    /**
     * Check if the requester can view the member's profile.
     */
    private function canViewProfile(User $requester, User $member): bool
    {
        // Admin/AssistantAdmin can view any member in their church
        if ($requester->isAdminOrAssistantAdmin()) {
            return $requester->church_id === $member->church_id;
        }

        // Servant can view members in their assigned classes
        if ($requester->isServant()) {
            if ($requester->church_id !== $member->church_id) {
                return false;
            }
            $servantClassIds = $requester->getServantClassIds();

            return $servantClassIds !== null && in_array($member->class_id, $servantClassIds, true);
        }

        // Member can view their own profile
        return $requester->id === $member->id;
    }
}
