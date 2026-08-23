<?php

namespace App\Contracts;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;

interface EventReservationServiceInterface
{
    /**
     * Approve a reservation. Only the responsible servant or admin can approve.
     */
    public function approve(Event $event, EventRegistration $registration, User $approver): EventRegistration;

    /**
     * Reject a reservation with an optional reason.
     */
    public function reject(Event $event, EventRegistration $registration, User $rejector, ?string $reason = null): EventRegistration;

    /**
     * Check if a user is the responsible servant for the event.
     */
    public function isResponsibleServant(Event $event, User $user): bool;
}
