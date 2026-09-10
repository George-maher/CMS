<?php

namespace App\Contracts;

interface MemberProfileServiceInterface
{
    /**
     * Get comprehensive member profile data including attendance and spiritual summary.
     *
     * @return array<string, mixed>
     */
    public function getProfile(int $memberId, int $requesterId): array;

    /**
     * Get attendance summary for a member.
     *
     * @return array{total_attended: int, total_sessions: int, absences: int, percentage: float, this_month: int, this_month_total: int}
     */
    public function getAttendanceSummary(int $memberId): array;

    /**
     * Get spiritual records summary for a member.
     *
     * @return array{total_records: int, mass_count: int, confession_count: int, communion_count: int, this_month_mass: int, this_month_confession: int, this_month_communion: int}
     */
    public function getSpiritualSummary(int $memberId): array;
}
