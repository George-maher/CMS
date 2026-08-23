<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $room_id
 * @property int $cell_number
 * @property string $type
 * @property bool $is_available
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EventRoom $room
 * @property-read EventAccommodation|null $accommodation
 */
class EventRoomCell extends Model
{
    protected $fillable = [
        'room_id',
        'cell_number',
        'type',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'cell_number' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    /** @return BelongsTo<EventRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(EventRoom::class, 'room_id');
    }

    /** @return BelongsTo<EventAccommodation, $this> */
    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(EventAccommodation::class, 'cell_id');
    }

    public function isServantReserved(): bool
    {
        return $this->type === 'servant_reserved';
    }

    public function isMemberCell(): bool
    {
        return $this->type === 'member';
    }
}
