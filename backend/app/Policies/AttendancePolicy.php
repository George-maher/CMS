<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Attendance $attendance): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Member can only view their own attendance
        if ($user->id === $attendance->user_id) {
            return true;
        }

        // Servant can view members of their assigned class
        if ($user->isServant()) {
            $servantClassIds = $user->getServantClassIds();
            if ($servantClassIds !== null && $attendance->user?->class_id !== null && in_array($attendance->user->class_id, $servantClassIds)) {
                return true;
            }
        }

        return false;
    }

    public function viewHistory(User $user, ?User $target = null): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($target === null) {
            return true;
        }

        // Member can only view their own history
        if ($user->id === $target->id) {
            return true;
        }

        // Servant can view history of members in their assigned class
        if ($user->isServant()) {
            $servantClassIds = $user->getServantClassIds();
            if ($servantClassIds !== null && $target->class_id !== null && in_array($target->class_id, $servantClassIds)) {
                return true;
            }
        }

        return false;
    }

    public function record(User $user): bool
    {
        return $user->isServant() || $user->isAdmin();
    }

    public function viewByClass(User $user): bool
    {
        return $user->isAdmin();
    }
}
