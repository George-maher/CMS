<?php

namespace App\Services;

use App\Contracts\EventRegistrationRepositoryInterface;
use App\Contracts\EventRegistrationServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Enums\EventAttendanceStatus;
use App\Enums\RegistrationStatus;
use App\Http\Resources\EventRegistrationResource;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventRegistrationService implements EventRegistrationServiceInterface
{
    public function __construct(
        private readonly EventRegistrationRepositoryInterface $repository,
        private readonly NotificationServiceInterface $notificationService,
    ) {}

    /** @param array<string, mixed> $filters */
    public function listForEvent(Event $event, int $perPage = 20, array $filters = []): array
    {
        $paginator = $this->repository->paginateForEvent($event, $perPage, $filters);

        return [
            'data' => EventRegistrationResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function register(Event $event, int $userId, ?int $registeredBy = null, ?string $notes = null): EventRegistration
    {
        return DB::transaction(function () use ($event, $userId, $registeredBy, $notes): EventRegistration {
            /** @var Event|null $locked */
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->first();

            if (! $locked) {
                throw ValidationException::withMessages([
                    'event' => ['Event not found.'],
                ]);
            }

            $this->assertEventAcceptsRegistrations($locked);

            $existing = EventRegistration::query()
                ->where('event_id', $locked->id)
                ->where('user_id', $userId)
                ->first();

            if ($existing && $existing->status !== RegistrationStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'user_id' => ['This member is already registered for this event.'],
                ]);
            }

            $status = $this->resolveTargetStatus($locked);
            $token = EventRegistration::generateQrToken();

            if ($existing) {
                $registration = $this->repository->update($existing, [
                    'status' => $status,
                    'registered_by' => $registeredBy ?? $existing->registered_by,
                    'attendance_status' => EventAttendanceStatus::NotCheckedIn->value,
                    'checked_in_at' => null,
                    'checked_in_by' => null,
                    'notes' => $notes ?? $existing->notes,
                    'qr_token' => $token,
                ]);
            } else {
                $registration = $this->repository->create([
                    'event_id' => $locked->id,
                    'user_id' => $userId,
                    'registered_by' => $registeredBy,
                    'status' => $status,
                    'qr_token' => $token,
                    'notes' => $notes,
                ]);
            }

            $this->notifyRegistration($locked, $registration);

            return $registration;
        });
    }

    public function registerSelf(Event $event, int $userId): EventRegistration
    {
        return $this->register($event, $userId, $userId, null);
    }

    public function confirm(EventRegistration $registration): EventRegistration
    {
        if (in_array($registration->status, [RegistrationStatus::Confirmed], true)) {
            throw ValidationException::withMessages([
                'status' => ['Registration is already confirmed.'],
            ]);
        }

        if ($registration->status === RegistrationStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => ['Cancelled registrations cannot be confirmed directly. Register again instead.'],
            ]);
        }

        if ($registration->status === RegistrationStatus::Waitlisted && ! $registration->event->hasAvailableCapacity()) {
            throw ValidationException::withMessages([
                'capacity' => ['The event is full. The member must remain on the waitlist.'],
            ]);
        }

        $updated = $this->repository->update($registration, [
            'status' => RegistrationStatus::Confirmed->value,
        ]);

        $this->notifyMember($updated, 'Registration Confirmed', "Your registration for '{$updated->event->name}' has been confirmed.");

        return $updated;
    }

    public function cancel(EventRegistration $registration): EventRegistration
    {
        if ($registration->status === RegistrationStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => ['Registration is already cancelled.'],
            ]);
        }

        $updated = $this->repository->update($registration, [
            'status' => RegistrationStatus::Cancelled->value,
            'attendance_status' => EventAttendanceStatus::NotCheckedIn->value,
            'checked_in_at' => null,
        ]);

        // Freeing a seat promotes the earliest waitlisted registration.
        $promoted = $this->repository->promoteFirstWaitlisted($updated->event);

        $this->notifyMember($updated, 'Registration Cancelled', "Your registration for '{$updated->event->name}' was cancelled.");

        if ($promoted) {
            $this->notifyMember($promoted, 'You Are Off the Waitlist', "A spot opened up for '{$promoted->event->name}'. Your registration is now pending confirmation.");
        }

        return $updated;
    }

    public function moveToWaitlist(EventRegistration $registration): EventRegistration
    {
        if (! in_array($registration->status, [RegistrationStatus::Pending, RegistrationStatus::Confirmed], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only active registrations can be moved to the waitlist.'],
            ]);
        }

        $updated = $this->repository->update($registration, [
            'status' => RegistrationStatus::Waitlisted->value,
        ]);

        $this->notifyMember($updated, 'Moved to Waitlist', "You have been moved to the waitlist for '{$updated->event->name}'.");

        return $updated;
    }

    public function updateNotes(EventRegistration $registration, string $notes): EventRegistration
    {
        return $this->repository->update($registration, ['notes' => $notes]);
    }

    public function remove(EventRegistration $registration): void
    {
        if ($registration->attendance_status === EventAttendanceStatus::CheckedIn) {
            throw ValidationException::withMessages([
                'attendance' => ['Checked-in registrations cannot be removed. Undo the check-in first.'],
            ]);
        }

        $event = $registration->event;

        $this->repository->delete($registration);

        if ($registration->status !== RegistrationStatus::Cancelled) {
            $promoted = $this->repository->promoteFirstWaitlisted($event);
            if ($promoted) {
                $this->notifyMember($promoted, 'You Are Off the Waitlist', "A spot opened up for '{$promoted->event->name}'. Your registration is now pending confirmation.");
            }
        }
    }

    public function checkInByToken(string $token, int $checkedInBy): EventRegistration
    {
        $registration = $this->repository->findByToken($token);

        if (! $registration) {
            throw ValidationException::withMessages([
                'qr_token' => ['Invalid registration QR code.'],
            ]);
        }

        if ($registration->status !== RegistrationStatus::Confirmed && $registration->status !== RegistrationStatus::Pending) {
            throw ValidationException::withMessages([
                'qr_token' => ["This registration cannot be checked in (status: {$registration->status->label()})."],
            ]);
        }

        return $this->checkIn($registration, $checkedInBy);
    }

    public function checkIn(EventRegistration $registration, int $checkedInBy): EventRegistration
    {
        if ($registration->attendance_status === EventAttendanceStatus::CheckedIn) {
            throw ValidationException::withMessages([
                'attendance' => ['Participant is already checked in.'],
            ]);
        }

        $updated = $this->repository->update($registration, [
            'attendance_status' => EventAttendanceStatus::CheckedIn->value,
            'checked_in_at' => now(),
            'checked_in_by' => $checkedInBy,
        ]);

        if ($updated->status === RegistrationStatus::Waitlisted) {
            $updated = $this->repository->update($updated, [
                'status' => RegistrationStatus::Confirmed->value,
            ]);
        }

        return $updated;
    }

    public function undoCheckIn(EventRegistration $registration): EventRegistration
    {
        if ($registration->attendance_status !== EventAttendanceStatus::CheckedIn) {
            throw ValidationException::withMessages([
                'attendance' => ['Participant is not checked in.'],
            ]);
        }

        return $this->repository->update($registration, [
            'attendance_status' => EventAttendanceStatus::NotCheckedIn->value,
            'checked_in_at' => null,
            'checked_in_by' => null,
        ]);
    }

    public function setAttendanceStatus(EventRegistration $registration, string $status): EventRegistration
    {
        if (! in_array($status, EventAttendanceStatus::values(), true)) {
            throw ValidationException::withMessages([
                'attendance_status' => ['Invalid attendance status.'],
            ]);
        }

        $data = ['attendance_status' => $status];

        if ($status !== EventAttendanceStatus::CheckedIn->value) {
            $data['checked_in_at'] = null;
            $data['checked_in_by'] = null;
        } else {
            $data['checked_in_at'] = now();
        }

        return $this->repository->update($registration, $data);
    }

    /** @return array<string, mixed> */
    public function myRegistrations(int $userId): array
    {
        $registrations = EventRegistration::query()
            ->where('user_id', $userId)
            ->whereIn('status', [
                RegistrationStatus::Pending->value,
                RegistrationStatus::Confirmed->value,
                RegistrationStatus::Waitlisted->value,
            ])
            ->with(['event', 'bus'])
            ->get();

        return [
            'data' => EventRegistrationResource::collection($registrations),
        ];
    }

    private function assertEventAcceptsRegistrations(Event $event): void
    {
        if (! $event->isRegistrationOpen()) {
            throw ValidationException::withMessages([
                'event' => ['This event is not accepting registrations (status: '.$event->status->label().').'],
            ]);
        }
    }

    private function resolveTargetStatus(Event $event): RegistrationStatus
    {
        return $event->hasAvailableCapacity()
            ? RegistrationStatus::Pending
            : RegistrationStatus::Waitlisted;
    }

    private function notifyRegistration(Event $event, EventRegistration $registration): void
    {
        $body = $registration->status === RegistrationStatus::Waitlisted
            ? "You are on the waitlist for '{$event->name}'."
            : "You are registered for '{$event->name}'.";

        $this->notificationService->create(
            $registration->user_id,
            $event->church_id ?? 0,
            'Registration Received',
            $body,
            'event_registration',
        );

        // Notify the responsible servant about the new registration
        if ($event->responsible_servant_id && $event->responsible_servant_id !== $registration->user_id) {
            $memberName = $registration->user->name ?? 'A member';
            $this->notificationService->create(
                $event->responsible_servant_id,
                $event->church_id ?? 0,
                'New Registration',
                "{$memberName} registered for '{$event->name}'.",
                'event_reservation',
            );
        }
    }

    private function notifyMember(EventRegistration $registration, string $title, string $body): void
    {
        $this->notificationService->create(
            $registration->user_id,
            $registration->event->church_id ?? 0,
            $title,
            $body,
            'event_registration',
        );
    }
}
