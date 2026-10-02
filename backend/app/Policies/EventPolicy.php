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
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->isServant() && (! $event->class_year_id || $this->eventTargetsServantsClass($user, $event))) {
            return true;
        }
        if ($user->isMember() && $event->is_active) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isServant();
    }

    public function update(User $user, Event $event): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->isServant() && $this->eventTargetsServantsClass($user, $event)) {
            return true;
        }
        if ($event->responsible_servant_id && $event->responsible_servant_id === $user->id && $event->church_id === $user->church_id) {
            return true;
        }

        return false;
    }

    public function delete(User $user, Event $event): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->isServant() && $this->eventTargetsServantsClass($user, $event)) {
            return true;
        }
        if ($event->responsible_servant_id && $event->responsible_servant_id === $user->id && $event->church_id === $user->church_id) {
            return true;
        }

        return false;
    }

    /**
     * Does this event target the class the servant actually belongs to?
     *
     * `events.class_year_id` is a legacy column name that now holds a
     * `classes.id` — every event FK was repointed at `classes` by
     * 2026_06_18_000002 / 2026_06_22_000001.
     *
     * `users.class_year_id` was NOT repointed: it is the one remaining
     * `class_year_id` still foreign-keyed to the deprecated `class_years`
     * table. Comparing the two compares ids from two different tables, so a
     * servant whose legacy `class_year_id` happened to equal some other
     * church's `classes.id` was granted update and delete on that event.
     *
     * The comparison therefore uses `class_id` on both sides, which is what
     * `EventAuthorizationService::allowedClassIds` already resolves against.
     */
    private function eventTargetsServantsClass(User $user, Event $event): bool
    {
        return $event->class_year_id !== null
            && $user->class_id !== null
            && (int) $event->class_year_id === (int) $user->class_id;
    }
}
