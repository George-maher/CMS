<?php

namespace App\Contracts;

use App\Models\Event;
use App\Models\EventAccommodation;
use App\Models\EventRoom;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EventAccommodationServiceInterface
{
    /**
     * Get accommodation dashboard statistics for an event.
     *
     * @return array<string, mixed>
     */
    public function dashboard(Event $event): array;

    /**
     * Bulk-create rooms for an event.
     *
     * @param  array<int, array{count: int, capacity: int}>  $roomGroups
     * @return array{rooms_created: int, cells_created: int, total_capacity: int, member_capacity: int}
     */
    public function bulkCreateRooms(Event $event, array $roomGroups): array;

    /**
     * Update a room's capacity.
     */
    public function updateRoom(Event $event, int $roomId, array $data): EventRoom;

    /**
     * Delete a room (only if no accommodations assigned).
     */
    public function deleteRoom(Event $event, int $roomId): void;

    /**
     * List rooms with cell counts for an event.
     */
    public function listRooms(Event $event, int $perPage = 20): LengthAwarePaginator;

    /**
     * Get a single room with its cells.
     */
    public function getRoom(Event $event, int $roomId): EventRoom;

    /**
     * Assign a user to a cell (accommodation).
     * Validates: registration belongs to the event and is approved, cell belongs
     * to the event and is available, user has no existing accommodation.
     */
    public function assignAccommodation(Event $event, int $registrationId, int $cellId): EventAccommodation;

    /**
     * Remove a user's accommodation assignment.
     */
    public function removeAccommodation(Event $event, int $registrationId): void;

    /**
     * List approved but unaccommodated registrations for an event.
     *
     * @return array{data: mixed, meta: array<string, int>}
     */
    public function unaccommodated(Event $event, int $perPage = 20): array;
}
