<?php

namespace App\Services;

use App\Contracts\EventReservationServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Enums\RegistrationStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventReservationService implements EventReservationServiceInterface
{
    /**
     * Statuses a reservation request can be reviewed from. Member-submitted
     * requests arrive as booked/not_reserved/thinking (plus pending for
     * direct registrations) — all of them are actionable.
     *
     * @return array<int, RegistrationStatus>
     */
    private const REVIEWABLE_STATUSES = [
        RegistrationStatus::Pending,
        RegistrationStatus::Booked,
        RegistrationStatus::NotReserved,
        RegistrationStatus::Thinking,
    ];

    public function __construct(
        private readonly NotificationServiceInterface $notificationService,
    ) {}

    public function approve(Event $event, EventRegistration $registration, User $approver): EventRegistration
    {
        $this->authorizeAction($event, $registration, $approver);

        return DB::transaction(function () use ($event, $registration, $approver): EventRegistration {
            /** @var EventRegistration|null $locked */
            $locked = EventRegistration::query()
                ->whereKey($registration->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw ValidationException::withMessages([
                    'registration' => ['Registration not found.'],
                ]);
            }

            if (! in_array($locked->status, self::REVIEWABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending reservations can be approved. Current status: '.$locked->status->label().'.'],
                ]);
            }

            $locked->update([
                'status' => RegistrationStatus::Approved->value,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                // Clear any stale opposite-side metadata from a prior review cycle.
                'rejected_by' => null,
                'rejected_at' => null,
            ]);

            // Notify the member
            $this->notificationService->create(
                $locked->user_id,
                $event->church_id ?? 0,
                'Reservation Approved',
                "Your reservation for '{$event->name}' has been approved.",
                'event_reservation',
                $event->id,
            );

            // Notify the responsible servant
            if ($event->responsible_servant_id && $event->responsible_servant_id !== $locked->user_id) {
                $this->notificationService->create(
                    $event->responsible_servant_id,
                    $event->church_id ?? 0,
                    'Reservation Approved',
                    "Reservation for user #{$locked->user_id} has been approved for '{$event->name}'.",
                    'event_reservation',
                    $event->id,
                );
            }

            return $locked->fresh(['user.classe', 'bus', 'accommodation.cell.room']) ?? $locked;
        });
    }

    public function reject(Event $event, EventRegistration $registration, User $rejector, ?string $reason = null): EventRegistration
    {
        $this->authorizeAction($event, $registration, $rejector);

        return DB::transaction(function () use ($event, $registration, $rejector, $reason): EventRegistration {
            /** @var EventRegistration|null $locked */
            $locked = EventRegistration::query()
                ->whereKey($registration->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw ValidationException::withMessages([
                    'registration' => ['Registration not found.'],
                ]);
            }

            if (! in_array($locked->status, self::REVIEWABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending reservations can be rejected. Current status: '.$locked->status->label().'.'],
                ]);
            }

            $locked->update([
                'status' => RegistrationStatus::Rejected->value,
                'rejection_reason' => $reason,
                'rejected_by' => $rejector->id,
                'rejected_at' => now(),
                // Clear any stale opposite-side metadata from a prior review cycle.
                'approved_by' => null,
                'approved_at' => null,
            ]);

            // Notify the member
            $this->notificationService->create(
                $locked->user_id,
                $event->church_id ?? 0,
                'Reservation Rejected',
                "Your reservation for '{$event->name}' has been rejected.".($reason ? " Reason: {$reason}" : ''),
                'event_reservation',
                $event->id,
            );

            return $locked->fresh(['user.classe', 'bus', 'accommodation.cell.room']) ?? $locked;
        });
    }

    public function isResponsibleServant(Event $event, User $user): bool
    {
        return $event->responsible_servant_id === $user->id;
    }

    private function authorizeAction(Event $event, EventRegistration $registration, User $user): void
    {
        // Must belong to same church
        if ($event->church_id !== $user->church_id) {
            throw ValidationException::withMessages([
                'authorization' => ['Forbidden.'],
            ]);
        }

        // Must be admin, assistant admin, or the responsible servant
        $isAdmin = in_array($user->role, [UserRole::Admin, UserRole::AssistantAdmin], true);
        $isResponsibleServant = $event->responsible_servant_id === $user->id;

        if (! $isAdmin && ! $isResponsibleServant) {
            throw ValidationException::withMessages([
                'authorization' => ['Only the responsible servant or an admin can manage reservations.'],
            ]);
        }

        // Registration must belong to this event
        if ($registration->event_id !== $event->id) {
            throw ValidationException::withMessages([
                'registration' => ['Registration does not belong to this event.'],
            ]);
        }
    }
}
