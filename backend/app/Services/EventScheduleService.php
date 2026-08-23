<?php

namespace App\Services;

use App\Contracts\EventScheduleServiceInterface;
use App\Models\Event;
use App\Models\EventBus;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventSpeaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventScheduleService implements EventScheduleServiceInterface
{
    /** @param array<string, mixed> $data */
    public function createSession(Event $event, array $data): EventSession
    {
        return EventSession::create([
            ...$data,
            'event_id' => $event->id,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateSession(Event $event, int $sessionId, array $data): EventSession
    {
        $session = $this->findSession($event, $sessionId);

        $session->update($data);

        return $session->refresh();
    }

    public function deleteSession(Event $event, int $sessionId): void
    {
        $this->findSession($event, $sessionId)->delete();
    }

    /** @param array<string, mixed> $data */
    public function createSpeaker(Event $event, array $data): EventSpeaker
    {
        return EventSpeaker::create([
            ...$data,
            'event_id' => $event->id,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateSpeaker(Event $event, int $speakerId, array $data): EventSpeaker
    {
        $speaker = $this->findSpeaker($event, $speakerId);

        $speaker->update($data);

        return $speaker->refresh();
    }

    public function deleteSpeaker(Event $event, int $speakerId): void
    {
        $this->findSpeaker($event, $speakerId)->delete();
    }

    /** @param array<string, mixed> $data */
    public function createBus(Event $event, array $data): EventBus
    {
        $capacityRaw = $data['capacity'] ?? null;

        if (! is_numeric($capacityRaw) || (int) $capacityRaw < 1) {
            throw ValidationException::withMessages([
                'capacity' => ['Bus capacity must be greater than zero.'],
            ]);
        }

        return EventBus::create([
            ...$data,
            'event_id' => $event->id,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateBus(Event $event, int $busId, array $data): EventBus
    {
        $bus = $this->findBus($event, $busId);

        if (isset($data['capacity'])) {
            $capacityRaw = $data['capacity'];
            $assigned = $bus->registrations()->count();

            if (! is_numeric($capacityRaw) || (int) $capacityRaw < 1 || (int) $capacityRaw < $assigned) {
                throw ValidationException::withMessages([
                    'capacity' => ['Capacity cannot be less than the number of assigned participants ('.$assigned.').'],
                ]);
            }
        }

        $bus->update($data);

        return $bus->refresh();
    }

    public function deleteBus(Event $event, int $busId): void
    {
        $bus = $this->findBus($event, $busId);
        $bus->registrations()->update(['bus_id' => null]);
        $bus->delete();
    }

    public function assignBus(Event $event, int $registrationId, ?int $busId): EventRegistration
    {
        return DB::transaction(function () use ($event, $registrationId, $busId): EventRegistration {
            /** @var EventRegistration|null $registration */
            $registration = EventRegistration::query()
                ->where('event_id', $event->id)
                ->whereKey($registrationId)
                ->lockForUpdate()
                ->first();

            if (! $registration) {
                throw ValidationException::withMessages([
                    'registration' => ['Registration not found.'],
                ]);
            }

            if ($busId === null) {
                return $this->persistAssignment($registration, null);
            }

            /** @var EventBus|null $bus */
            $bus = EventBus::query()
                ->where('event_id', $event->id)
                ->whereKey($busId)
                ->lockForUpdate()
                ->first();

            if (! $bus) {
                throw ValidationException::withMessages([
                    'bus_id' => ['Bus not found for this event.'],
                ]);
            }

            $isSameBus = $registration->bus_id === $bus->id;
            $assignedCount = $bus->registrations()->count();

            if (! $isSameBus && $assignedCount >= $bus->capacity) {
                throw ValidationException::withMessages([
                    'bus_id' => ["Bus {$bus->bus_number} is full ({$bus->capacity} seats)."],
                ]);
            }

            return $this->persistAssignment($registration, $bus->id);
        });
    }

    private function persistAssignment(EventRegistration $registration, ?int $busId): EventRegistration
    {
        $registration->update(['bus_id' => $busId]);

        return $registration->fresh(['user.classe', 'bus']) ?? $registration;
    }

    private function findSession(Event $event, int $sessionId): EventSession
    {
        $session = EventSession::query()->where('event_id', $event->id)->find($sessionId);

        if (! $session) {
            throw ValidationException::withMessages([
                'session' => ['Session not found for this event.'],
            ]);
        }

        return $session;
    }

    private function findSpeaker(Event $event, int $speakerId): EventSpeaker
    {
        $speaker = EventSpeaker::query()->where('event_id', $event->id)->find($speakerId);

        if (! $speaker) {
            throw ValidationException::withMessages([
                'speaker' => ['Speaker not found for this event.'],
            ]);
        }

        return $speaker;
    }

    private function findBus(Event $event, int $busId): EventBus
    {
        $bus = EventBus::query()->where('event_id', $event->id)->find($busId);

        if (! $bus) {
            throw ValidationException::withMessages([
                'bus' => ['Bus not found for this event.'],
            ]);
        }

        return $bus;
    }
}
