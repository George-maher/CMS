<?php

namespace App\Services;

use App\Contracts\EventLifecycleServiceInterface;
use App\Contracts\EventRepositoryInterface;
use App\Contracts\NotificationServiceInterface;
use App\Enums\EventStatus;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Validation\ValidationException;

class EventLifecycleService implements EventLifecycleServiceInterface
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly NotificationServiceInterface $notificationService,
    ) {}

    public function publish(Event $event): Event
    {
        if ($event->status !== EventStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => ['Only draft events can be published (current status: '.$event->status->label().').'],
            ]);
        }

        return $this->applyStatus($event, EventStatus::Open);
    }

    public function closeRegistration(Event $event): Event
    {
        if ($event->status !== EventStatus::Open) {
            throw ValidationException::withMessages([
                'status' => ['Registration can only be closed for open events (current status: '.$event->status->label().').'],
            ]);
        }

        return $this->applyStatus($event, EventStatus::Closed);
    }

    public function reopenRegistration(Event $event): Event
    {
        if ($event->status !== EventStatus::Closed) {
            throw ValidationException::withMessages([
                'status' => ['Only closed events can reopen registration (current status: '.$event->status->label().').'],
            ]);
        }

        return $this->applyStatus($event, EventStatus::Open);
    }

    public function cancel(Event $event): Event
    {
        if ($event->status === EventStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => ['Event is already cancelled.'],
            ]);
        }

        if ($event->status === EventStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => ['Completed events cannot be cancelled.'],
            ]);
        }

        $updated = $this->applyStatus($event, EventStatus::Cancelled);

        // Notify every active participant about the cancellation.
        $updated->registrations()
            ->whereIn('status', ['pending', 'confirmed', 'waitlisted'])
            ->with('user:id')
            ->get()
            ->each(function ($registration) use ($updated): void {
                $this->notificationService->create(
                    $registration->user_id,
                    $updated->church_id ?? 0,
                    'Event Cancelled',
                    "The event '{$updated->name}' has been cancelled.",
                    'event',
                );
            });

        return $updated;
    }

    public function complete(Event $event): Event
    {
        if (! in_array($event->status, [EventStatus::Open, EventStatus::Closed], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only open or closed events can be completed (current status: '.$event->status->label().').'],
            ]);
        }

        return $this->applyStatus($event, EventStatus::Completed);
    }

    /** @return array<string, mixed> */
    public function duplicate(Event $event, int $userId): array
    {
        $copy = $this->eventRepository->create(array_merge($event->only([
            'type',
            'description',
            'location',
            'end_date',
            'start_time',
            'end_time',
            'max_capacity',
            'theme',
            'target_age_group',
            'target_group',
            'destination',
            'departure_location',
            'transportation_type',
            'coordinator_name',
            'coordinator_phone',
            'price_per_participant',
            'is_all_classes',
            'class_year_id',
            'church_id',
        ]), [
            'name' => $event->name.' (Copy)',
            'status' => EventStatus::Draft->value,
            'is_active' => false,
            'created_by' => $userId,
            'image' => null,
        ]));

        return [
            'data' => new EventResource($copy->load(['creator', 'classe', 'targets.classe'])),
        ];
    }

    private function applyStatus(Event $event, EventStatus $status): Event
    {
        $data = [
            'status' => $status->value,
        ];

        if ($status === EventStatus::Open) {
            $data['is_active'] = true;
        } elseif ($status === EventStatus::Cancelled) {
            $data['is_active'] = false;
        }

        $this->eventRepository->update($event->id, $data);

        /** @var Event|null $fresh */
        $fresh = $this->eventRepository->findById($event->id);

        if (! $fresh) {
            throw ValidationException::withMessages([
                'event' => ['Event not found.'],
            ]);
        }

        return $fresh;
    }
}
