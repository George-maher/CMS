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
        if ($user->isAdmin()) return true;
        if ($user->isServant() && $attendance->class_year_id === $user->class_year_id) return true;
        return $user->id === $attendance->user_id;
    }

    public function viewHistory(User $user, ?User $target = null): bool
    {
        if ($user->isAdmin()) return true;
        if ($target === null) return true;
        if ($user->id === $target->id) return true;
        if ($user->isServant() && $target->class_year_id === $user->class_year_id) return true;
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
