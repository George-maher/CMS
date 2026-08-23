<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $registration_id
 * @property int $cell_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EventRegistration $registration
 * @property-read EventRoomCell $cell
 */
class EventAccommodation extends Model
{
    protected $fillable = [
        'registration_id',
        'cell_id',
    ];

    /** @return BelongsTo<EventRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class);
    }

    /** @return BelongsTo<EventRoomCell, $this> */
    public function cell(): BelongsTo
    {
        return $this->belongsTo(EventRoomCell::class, 'cell_id');
    }
}
