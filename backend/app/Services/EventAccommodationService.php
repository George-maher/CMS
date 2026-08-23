<?php

namespace App\Services;

use App\Contracts\EventAccommodationServiceInterface;
use App\Enums\RegistrationStatus;
use App\Models\Event;
use App\Models\EventAccommodation;
use App\Models\EventRegistration;
use App\Models\EventRoom;
use App\Models\EventRoomCell;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventAccommodationService implements EventAccommodationServiceInterface
{
    /** @return array<string, mixed> */
    public function dashboard(Event $event): array
    {
        $totalRooms = $event->rooms()->count();
        $totalCapacity = (int) $event->rooms()->sum('capacity');
        $totalMemberCapacity = (int) $event->rooms()->sum('member_capacity');
        $totalServantCells = EventRoomCell::query()
            ->whereHas('room', fn ($q) => $q->where('event_id', $event->id))
            ->where('type', 'servant_reserved')
            ->count();

        $approvedCount = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', RegistrationStatus::Approved->value)
            ->count();

        $accommodatedCount = EventAccommodation::query()
            ->whereHas('registration', fn ($q) => $q->where('event_id', $event->id))
            ->count();

        $occupiedMemberCells = EventRoomCell::query()
            ->whereHas('room', fn ($q) => $q->where('event_id', $event->id))
            ->where('type', 'member')
            ->where('is_available', false)
            ->count();

        $availableMemberCells = EventRoomCell::query()
            ->whereHas('room', fn ($q) => $q->where('event_id', $event->id))
            ->where('type', 'member')
            ->where('is_available', true)
            ->count();

        return [
            'total_rooms' => $totalRooms,
            'total_capacity' => $totalCapacity,
            'servant_capacity' => $totalServantCells,
            'member_capacity' => $totalMemberCapacity,
            'approved_reservations' => $approvedCount,
            'accommodated' => $accommodatedCount,
            'not_accommodated' => max(0, $approvedCount - $accommodatedCount),
            'occupied_member_cells' => $occupiedMemberCells,
            'available_member_cells' => $availableMemberCells,
        ];
    }

    /** @param array<int, array{count: int, capacity: int}> $roomGroups */
    public function bulkCreateRooms(Event $event, array $roomGroups): array
    {
        $totalRooms = 0;
        $cellsCreated = 0;
        $totalCapacity = 0;
        $memberCapacity = 0;

        DB::transaction(function () use ($event, $roomGroups, &$totalRooms, &$cellsCreated, &$totalCapacity, &$memberCapacity) {
            $roomNumber = 1;

            foreach ($roomGroups as $group) {
                $count = (int) $group['count'];
                $capacity = (int) $group['capacity'];
                $memCap = $capacity - 1;

                for ($i = 0; $i < $count; $i++) {
                    /** @var EventRoom $room */
                    $room = EventRoom::create([
                        'event_id' => $event->id,
                        'room_number' => $roomNumber,
                        'capacity' => $capacity,
                        'member_capacity' => max(0, $memCap),
                    ]);

                    EventRoomCell::create([
                        'room_id' => $room->id,
                        'cell_number' => 1,
                        'type' => 'servant_reserved',
                        'is_available' => false,
                    ]);

                    for ($c = 2; $c <= $capacity; $c++) {
                        EventRoomCell::create([
                            'room_id' => $room->id,
                            'cell_number' => $c,
                            'type' => 'member',
                            'is_available' => true,
                        ]);
                        $cellsCreated++;
                    }

                    $cellsCreated++; // servant cell
                    $totalRooms++;
                    $totalCapacity += $capacity;
                    $memberCapacity += max(0, $memCap);
                    $roomNumber++;
                }
            }
        });

        return [
            'rooms_created' => $totalRooms,
            'cells_created' => $cellsCreated,
            'total_capacity' => $totalCapacity,
            'member_capacity' => $memberCapacity,
        ];
    }

    public function updateRoom(Event $event, int $roomId, array $data): EventRoom
    {
        $room = $this->findRoom($event, $roomId);

        if (isset($data['capacity'])) {
            /** @var int|string|float|null $rawCapacity */
            $rawCapacity = $data['capacity'];
            $newCapacity = is_int($rawCapacity) ? $rawCapacity : intval($rawCapacity);
            $currentMemberCells = $room->cells()->where('type', 'member')->count();

            // Cannot reduce capacity below occupied member cells
            if ($newCapacity < $currentMemberCells + 1) {
                throw ValidationException::withMessages([
                    'capacity' => ['Cannot reduce capacity below the number of occupied cells ('.$currentMemberCells.'). Minimum allowed: '.($currentMemberCells + 1).'.'],
                ]);
            }

            $newMemberCapacity = $newCapacity - 1;
            $room->update([
                'capacity' => $newCapacity,
                'member_capacity' => max(0, $newMemberCapacity),
            ]);

            $currentCells = $room->cells()->count();
            $currentTotal = $room->capacity;

            if ($newCapacity > $currentTotal) {
                // Add new member cells
                for ($c = $currentCells + 1; $c <= $newCapacity; $c++) {
                    EventRoomCell::create([
                        'room_id' => $room->id,
                        'cell_number' => $c,
                        'type' => 'member',
                        'is_available' => true,
                    ]);
                }
            } elseif ($newCapacity < $currentTotal) {
                // Remove unoccupied member cells from the end
                $room->cells()
                    ->where('type', 'member')
                    ->where('is_available', true)
                    ->where('cell_number', '>', $newCapacity)
                    ->delete();
            }
        }

        if (isset($data['is_active'])) {
            $room->update(['is_active' => (bool) $data['is_active']]);
        }

        return $room->refresh();
    }

    public function deleteRoom(Event $event, int $roomId): void
    {
        $room = $this->findRoom($event, $roomId);

        $occupiedCells = $room->cells()->where('is_available', false)->count();
        if ($occupiedCells > 0) {
            throw ValidationException::withMessages([
                'room' => ['Cannot delete room with assigned accommodations. Remove accommodations first.'],
            ]);
        }

        $room->delete();
    }

    public function listRooms(Event $event, int $perPage = 20): LengthAwarePaginator
    {
        return $event->rooms()
            ->withCount([
                'cells as total_cells_count',
                'cells as occupied_cells_count' => function (Builder $q) {
                    $q->where('is_available', false);
                },
                'cells as available_cells_count' => function (Builder $q) {
                    $q->where('is_available', true);
                },
            ])
            ->orderBy('room_number')
            ->paginate($perPage);
    }

    public function getRoom(Event $event, int $roomId): EventRoom
    {
        return $this->findRoom($event, $roomId)->load(['cells.accommodation.registration.user']);
    }

    public function assignAccommodation(int $registrationId, int $cellId): EventAccommodation
    {
        return DB::transaction(function () use ($registrationId, $cellId): EventAccommodation {
            /** @var EventRegistration|null $registration */
            $registration = EventRegistration::query()
                ->whereKey($registrationId)
                ->lockForUpdate()
                ->first();

            if (! $registration) {
                throw ValidationException::withMessages([
                    'registration' => ['Registration not found.'],
                ]);
            }

            if ($registration->status !== RegistrationStatus::Approved) {
                throw ValidationException::withMessages([
                    'status' => ['Only approved reservations can be assigned accommodation.'],
                ]);
            }

            // Check if user already has accommodation for this event
            $existingAccommodation = EventAccommodation::query()
                ->whereHas('registration', function ($q) use ($registration) {
                    $q->where('event_id', $registration->event_id)
                        ->where('user_id', $registration->user_id);
                })
                ->first();

            if ($existingAccommodation) {
                throw ValidationException::withMessages([
                    'accommodation' => ['This user already has an accommodation assignment for this event.'],
                ]);
            }

            /** @var EventRoomCell|null $cell */
            $cell = EventRoomCell::query()
                ->whereKey($cellId)
                ->lockForUpdate()
                ->first();

            if (! $cell) {
                throw ValidationException::withMessages([
                    'cell' => ['Cell not found.'],
                ]);
            }

            if ($cell->type === 'servant_reserved') {
                throw ValidationException::withMessages([
                    'cell' => ['Cannot assign members to servant-reserved cells.'],
                ]);
            }

            if (! $cell->is_available) {
                throw ValidationException::withMessages([
                    'cell' => ['This cell is already occupied.'],
                ]);
            }

            // Verify room belongs to same event
            $room = $cell->room;
            if ($room->event_id !== $registration->event_id) {
                throw ValidationException::withMessages([
                    'cell' => ['This cell does not belong to the same event.'],
                ]);
            }

            // Mark cell as occupied
            $cell->update(['is_available' => false]);

            // Create accommodation record
            /** @var EventAccommodation $accommodation */
            $accommodation = EventAccommodation::create([
                'registration_id' => $registration->id,
                'cell_id' => $cell->id,
            ]);

            return $accommodation;
        });
    }

    public function removeAccommodation(int $registrationId): void
    {
        DB::transaction(function () use ($registrationId): void {
            /** @var EventAccommodation|null $accommodation */
            $accommodation = EventAccommodation::query()
                ->where('registration_id', $registrationId)
                ->lockForUpdate()
                ->first();

            if (! $accommodation) {
                throw ValidationException::withMessages([
                    'accommodation' => ['No accommodation assignment found.'],
                ]);
            }

            // Release the cell
            EventRoomCell::query()
                ->whereKey($accommodation->cell_id)
                ->update(['is_available' => true]);

            $accommodation->delete();
        });
    }

    public function unaccommodated(Event $event, int $perPage = 20): array
    {
        $paginator = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', RegistrationStatus::Approved->value)
            ->whereDoesntHave('accommodation')
            ->with(['user.classe'])
            ->orderBy('created_at')
            ->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function findRoom(Event $event, int $roomId): EventRoom
    {
        $room = EventRoom::query()
            ->where('event_id', $event->id)
            ->find($roomId);

        if (! $room) {
            throw ValidationException::withMessages([
                'room' => ['Room not found for this event.'],
            ]);
        }

        return $room;
    }
}
