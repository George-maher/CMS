<?php

namespace App\Repositories;

use App\Contracts\EventRegistrationRepositoryInterface;
use App\Enums\RegistrationStatus;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EventRegistrationRepository implements EventRegistrationRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, EventRegistration>
     */
    public function paginateForEvent(Event $event, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = EventRegistration::query()
            ->where('event_id', $event->id)
            ->with(['user.classe', 'bus', 'registrar']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        if (! empty($filters['attendance_status'])) {
            $query->where('attendance_status', $filters['attendance_status']);
        }

        if (! empty($filters['bus_id'])) {
            $query->where('bus_id', $filters['bus_id']);
        } elseif (! empty($filters['without_bus'])) {
            $query->whereNull('bus_id');
        }

        if (! empty($filters['search'])) {
            /** @var string $search */
            $search = $filters['search'];
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }

        // Portable status priority ordering (MySQL's FIELD() does not exist on
        // PostgreSQL/SQLite). Bindings keep literals safely quoted.
        return $query->orderByRaw(
            'CASE status WHEN ? THEN 1 WHEN ? THEN 2 WHEN ? THEN 3 ELSE 4 END',
            [
                RegistrationStatus::Confirmed->value,
                RegistrationStatus::Pending->value,
                RegistrationStatus::Waitlisted->value,
            ]
        )
            ->orderBy('created_at')
            ->paginate($perPage);
    }

    public function findByToken(string $token): ?EventRegistration
    {
        return EventRegistration::query()
            ->with(['user.classe', 'bus'])
            ->where('qr_token', $token)
            ->first();
    }

    public function findById(int $id): ?EventRegistration
    {
        return EventRegistration::query()
            ->with(['user.classe', 'event', 'bus'])
            ->find($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): EventRegistration
    {
        /** @var EventRegistration $registration */
        $registration = EventRegistration::create($data);

        return $registration->fresh(['user.classe', 'bus']) ?? $registration;
    }

    /** @param array<string, mixed> $data */
    public function update(EventRegistration $registration, array $data): EventRegistration
    {
        $registration->update($data);

        return $registration->fresh(['user.classe', 'bus']) ?? $registration;
    }

    public function delete(EventRegistration $registration): bool
    {
        return (bool) $registration->delete();
    }

    public function promoteFirstWaitlisted(Event $event): ?EventRegistration
    {
        return DB::transaction(function () use ($event): ?EventRegistration {
            $candidate = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('status', RegistrationStatus::Waitlisted->value)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (! $candidate) {
                return null;
            }

            $candidate->update([
                'status' => RegistrationStatus::Pending->value,
            ]);

            return $candidate->fresh(['user.classe', 'bus']);
        });
    }
}
