<?php

namespace App\Policies;

use App\Contracts\ScopeResolverInterface;
use App\Models\ProfileUpdateRequest;
use App\Models\User;

class ProfileUpdateRequestPolicy
{
    public function __construct(private readonly ScopeResolverInterface $scopeResolver) {}

    public function viewAny(User $user): bool
    {
        return $user->isAdminOrAssistantAdmin() || $user->isServant() || $user->isStageAdmin();
    }

    public function view(User $user, ProfileUpdateRequest $request): bool
    {
        // Owner can always view their own request
        if ($user->id === $request->user_id) {
            return true;
        }

        // Admin/assistant admin in the same church can view
        if ($user->isAdminOrAssistantAdmin() && $request->church_id === $user->church_id) {
            return true;
        }

        // Servant who is responsible for the member can view
        if ($user->isServant() && $request->reviewer_id === $user->id) {
            return true;
        }

        // Stage admin can view requests from members within their stage
        if ($user->isStageAdmin() && $this->canReviewRequest($user, $request)) {
            return true;
        }

        return false;
    }

    public function approve(User $user, ProfileUpdateRequest $request): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        if ($request->church_id !== $user->church_id) {
            return false;
        }

        if ($user->isAdminOrAssistantAdmin()) {
            return true;
        }

        if ($user->isServant()) {
            return true;
        }

        return $user->isStageAdmin() && $this->canReviewRequest($user, $request);
    }

    public function reject(User $user, ProfileUpdateRequest $request): bool
    {
        return $this->approve($user, $request);
    }

    private function canReviewRequest(User $stageAdmin, ProfileUpdateRequest $request): bool
    {
        $target = $request->user;

        return $target !== null && $this->scopeResolver->canAccessUser($stageAdmin, $target);
    }
}
