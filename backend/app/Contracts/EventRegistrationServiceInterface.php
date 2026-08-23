<?php

namespace App\Contracts;

use App\Models\Event;
use App\Models\EventRegistration;

interface EventRegistrationServiceInterface
{
    /** @param array<string, mixed> $filters */
    public function listForEvent(Event $event, int $perPage = 20, array $filters = []): array;

    public function register(Event $event, int $userId, ?int $registeredBy = null, ?string $notes = null): EventRegistration;

    public function registerSelf(Event $event, int $userId): EventRegistration;

    public function confirm(EventRegistration $registration): EventRegistration;

    public function cancel(EventRegistration $registration): EventRegistration;

    public function moveToWaitlist(EventRegistration $registration): EventRegistration;

    public function updateNotes(EventRegistration $registration, string $notes): EventRegistration;

    public function remove(EventRegistration $registration): void;

    public function checkInByToken(string $token, int $checkedInBy): EventRegistration;

    public function checkIn(EventRegistration $registration, int $checkedInBy): EventRegistration;

    public function undoCheckIn(EventRegistration $registration): EventRegistration;

    public function setAttendanceStatus(EventRegistration $registration, string $status): EventRegistration;

    public function myRegistrations(int $userId): array;
}
