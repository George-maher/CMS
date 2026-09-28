<?php

namespace App\Policies;

use App\Models\Feedback;
use App\Models\User;

class FeedbackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isServant();
    }

    public function view(User $user, Feedback $feedback): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isServant()) {
            $classIds = $user->getServantClassIds();
            return $classIds !== null && in_array($feedback->class_year_id, $classIds, true);
        }
        if ($user->isMember() && $user->id === $feedback->user_id) return true;
        return false;
    }

    public function create(User $user): bool
    {
        return $user->isMember();
    }

    public function resolve(User $user, Feedback $feedback): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isServant()) {
            $classIds = $user->getServantClassIds();
            return $classIds !== null && in_array($feedback->class_year_id, $classIds, true);
        }
        return false;
    }

    public function reply(User $user, Feedback $feedback): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isServant()) {
            $classIds = $user->getServantClassIds();
            return $classIds !== null && in_array($feedback->class_year_id, $classIds, true);
        }
        return false;
    }
}
