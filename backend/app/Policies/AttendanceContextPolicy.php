<?php

namespace App\Policies;

use App\Models\AttendanceContext;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AttendanceContextPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isServant();
    }

    public function view(User $user, ?AttendanceContext $context = null): bool
    {
        if (! ($user->isAdmin() || $user->isServant())) {
            return false;
        }

        return $this->belongsToUserChurch($user, $context);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isServant();
    }

    public function update(User $user, ?AttendanceContext $context = null): bool
    {
        if (! ($user->isAdmin() || $user->isServant())) {
            return false;
        }

        return $this->belongsToUserChurch($user, $context);
    }

    public function delete(User $user, ?AttendanceContext $context = null): bool
    {
        if (! ($user->isAdmin() || $user->isAssistantAdmin())) {
            return false;
        }

        return $this->belongsToUserChurch($user, $context);
    }

    public function toggleActive(User $user, ?AttendanceContext $context = null): bool
    {
        if (! ($user->isAdmin() || $user->isAssistantAdmin())) {
            return false;
        }

        return $this->belongsToUserChurch($user, $context);
    }

    public function restore(User $user, ?AttendanceContext $context = null): bool
    {
        if (! ($user->isAdmin() || $user->isAssistantAdmin() || $user->isServant())) {
            return false;
        }

        return $this->belongsToUserChurch($user, $context);
    }

    private function belongsToUserChurch(User $user, ?AttendanceContext $context): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Nullable church_id contexts are shared defaults (e.g., system defaults).
        return $context === null
            || $context->church_id === null
            || $context->church_id === $user->church_id;
    }
}
