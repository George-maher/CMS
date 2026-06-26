<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Event $event): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isServant() && (!$event->class_year_id || $event->class_year_id === $user->class_year_id)) return true;
        if ($user->isMember() && $event->is_active) return true;
        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isServant();
    }

    public function update(User $user, Event $event): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isServant() && $event->class_year_id === $user->class_year_id) return true;
        return false;
    }

    public function delete(User $user, Event $event): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isServant() && $event->class_year_id === $user->class_year_id) return true;
        return false;
    }
}
