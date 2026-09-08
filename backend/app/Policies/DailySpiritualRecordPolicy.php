<?php

namespace App\Policies;

use App\Models\DailySpiritualRecord;
use App\Models\User;

class DailySpiritualRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isServant();
    }

    public function view(User $user, DailySpiritualRecord $record): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->id === $record->user_id) {
            return true;
        }

        if ($user->isServant()) {
            $servantClassIds = $user->getServantClassIds();
            if ($servantClassIds !== null && $user->class_id !== null && in_array($user->class_id, $servantClassIds)) {
                return true;
            }
        }

        return false;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, DailySpiritualRecord $record): bool
    {
        return $user->id === $record->user_id;
    }

    public function delete(User $user, DailySpiritualRecord $record): bool
    {
        return $user->id === $record->user_id;
    }
}
