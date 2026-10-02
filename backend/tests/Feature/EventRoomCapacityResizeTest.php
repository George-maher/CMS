<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Event;
use App\Models\EventRoom;
use App\Models\EventRoomCell;
use App\Services\EventAccommodationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * PHASE 1C — B-4: resizing a room must actually sync its cell inventory.
 *
 * Why this file exists:
 *
 * `EventAccommodationService::updateRoom()` read `$currentTotal` from
 * `$room->capacity` AFTER `$room->update(['capacity' => $newCapacity])`.
 * Eloquent's update() writes the new value into the model's attributes, so
 * the comparison compared the new capacity to itself:
 *
 *   - increasing capacity: `newCapacity > currentTotal` was never true, so
 *     no member cells were created — the room reported a larger
 *     `member_capacity` while the cell grid (which members actually select
 *     from) stayed at the old size;
 *   - decreasing capacity: the guard compared against ALL member cells
 *     (which for a consistent inventory equals capacity-1), so every
 *     reduction was refused regardless of occupancy, and the removal branch
 *     was unreachable twice over.
 *
 * The endpoint is live: PUT/PATCH /api/v1/events/{id}/accommodation/rooms/
 * {roomId} (routes/api.php) validates `capacity` and calls this service.
 * No existing test exercised roomsUpdate, which is why the suite stayed green.
 *
 * Cell layout mirrors bulkCreateRooms(): cell 1 is the reserved servant
 * cell, cells 2..capacity are member cells.
 *
 * Service-level tests (not HTTP) — the defect is in the service, and this
 * keeps the fixture free of auth/permission setup.
 */
class EventRoomCapacityResizeTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $church = Church::factory()->create();
        $this->event = Event::factory()->create(['church_id' => $church->id]);
    }

    /**
     * Build a room exactly as bulkCreateRooms() does: cell 1 servant-reserved
     * (unavailable), cells 2..capacity member (available).
     */
    private function makeRoom(int $capacity): EventRoom
    {
        $room = EventRoom::create([
            'event_id' => $this->event->id,
            'room_number' => 1,
            'capacity' => $capacity,
            'member_capacity' => max(0, $capacity - 1),
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
        }

        return $room;
    }

    private function service(): EventAccommodationService
    {
        return app(EventAccommodationService::class);
    }

    public function test_increasing_capacity_creates_the_new_member_cells(): void
    {
        $room = $this->makeRoom(4);

        $updated = $this->service()->updateRoom($this->event, $room->id, ['capacity' => 6]);

        $this->assertSame(6, (int) $updated->capacity);
        $this->assertSame(5, (int) $updated->member_capacity, 'member_capacity is capacity minus the reserved servant cell.');

        $this->assertSame(
            6,
            $updated->cells()->count(),
            'A capacity increase must create the new cells — otherwise the cell grid '
            .'members select from stays smaller than the reported capacity.'
        );
        $this->assertTrue(
            $updated->cells()->where('cell_number', 5)->where('type', 'member')->exists(),
            'The new member cells must be numbered consecutively after the existing ones.'
        );
        $this->assertTrue(
            $updated->cells()->where('cell_number', 6)->where('type', 'member')->exists()
        );
    }

    public function test_decreasing_capacity_removes_unoccupied_member_cells(): void
    {
        $room = $this->makeRoom(6);

        $updated = $this->service()->updateRoom($this->event, $room->id, ['capacity' => 4]);

        $this->assertSame(4, (int) $updated->capacity);
        $this->assertSame(3, (int) $updated->member_capacity);
        $this->assertSame(
            4,
            $updated->cells()->count(),
            'A capacity reduction on an empty room must delete the member cells above '
            .'the new capacity (servant cell + cells 2..4 remain).'
        );
        $this->assertFalse($updated->cells()->where('cell_number', '>', 4)->exists());
    }

    public function test_decreasing_capacity_keeps_occupied_member_cells(): void
    {
        $room = $this->makeRoom(6);

        // Occupy member cells 5 and 6 the way assign() does: is_available=false.
        EventRoomCell::query()
            ->where('room_id', $room->id)
            ->whereIn('cell_number', [5, 6])
            ->update(['is_available' => false]);

        $updated = $this->service()->updateRoom($this->event, $room->id, ['capacity' => 4]);

        // Occupied assignments are never destroyed by a capacity edit: cells 5
        // and 6 are occupied, so nothing above the new capacity qualifies for
        // deletion even though it is numbered above 4.
        $this->assertSame(4, (int) $updated->capacity);
        $this->assertTrue(
            $updated->cells()->where('cell_number', 5)->where('is_available', false)->exists(),
            'An occupied cell must survive a capacity reduction.'
        );
        $this->assertTrue(
            $updated->cells()->where('cell_number', 6)->where('is_available', false)->exists()
        );
        $this->assertFalse(
            $updated->cells()->where('is_available', true)->where('cell_number', '>', 4)->exists(),
            'Only UNOCCUPIED member cells above the new capacity may be deleted.'
        );
    }

    public function test_reduction_below_the_occupied_member_cells_is_refused(): void
    {
        $room = $this->makeRoom(6);

        EventRoomCell::query()
            ->where('room_id', $room->id)
            ->whereIn('cell_number', [5, 6])
            ->update(['is_available' => false]);

        // Two occupied member cells + the reserved servant cell => minimum
        // capacity is 3; 2 would strand an assigned member.
        try {
            $this->service()->updateRoom($this->event, $room->id, ['capacity' => 2]);
            $this->fail('Expected a ValidationException for a reduction below the occupied cells.');
        } catch (ValidationException $e) {
            $message = $e->errors()['capacity'][0] ?? '';
            $this->assertStringContainsString('occupied cells (2)', $message);
            $this->assertStringContainsString('Minimum allowed: 3', $message);
        }

        // The refusal must not have mutated the room.
        $room->refresh();
        $this->assertSame(6, (int) $room->capacity);
        $this->assertSame(6, $room->cells()->count());
    }
}
