<?php

namespace App\Policies;

use App\Enums\PasswordResetRequestStatus;
use App\Models\PasswordResetRequest;
use App\Models\User;

class PasswordResetRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdminOrAssistantAdmin();
    }

    public function view(User $user, PasswordResetRequest $request): bool
    {
        if ($user->isAdminOrAssistantAdmin()) return true;
        return $user->id === $request->user_id;
    }

    public function approve(User $user, PasswordResetRequest $request): bool
    {
        return $user->isAdminOrAssistantAdmin()
            && $request->isPending()
            && $request->user->church_id === $user->church_id;
    }

    public function reject(User $user, PasswordResetRequest $request): bool
    {
        return $user->isAdminOrAssistantAdmin()
            && $request->isPending()
            && $request->user->church_id === $user->church_id;
    }

    public function create(User $user): bool
    {
        return $user->isMember() || $user->isServant();
    }
}
