<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property int $room_number
 * @property int $capacity
 * @property int $member_capacity
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Event $event
 * @property-read Collection<int, EventRoomCell> $cells
 * @property-read int|null $cells_count
 */
class EventRoom extends Model
{
    protected $fillable = [
        'event_id',
        'room_number',
        'capacity',
        'member_capacity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'member_capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<EventRoomCell, $this> */
    public function cells(): HasMany
    {
        return $this->hasMany(EventRoomCell::class, 'room_id')->orderBy('cell_number');
    }

    /**
     * Cells available for member assignment.
     *
     * @return HasMany<EventRoomCell, $this>
     */
    public function availableMemberCells(): HasMany
    {
        return $this->cells()
            ->where('type', 'member')
            ->where('is_available', true);
    }

    public function totalCellsCount(): int
    {
        return $this->cells()->count();
    }

    public function occupiedCellsCount(): int
    {
        return $this->cells()->where('is_available', false)->count();
    }

    public function availableCellsCount(): int
    {
        return $this->cells()->where('is_available', true)->count();
    }
}
