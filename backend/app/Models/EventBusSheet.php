<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $registration_id
 * @property int $bus_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EventRegistration $registration
 * @property-read EventBus $bus
 */
class EventBusSheet extends Model
{
    protected $table = 'event_bus_sheets';

    protected $fillable = [
        'registration_id',
        'bus_id',
    ];

    /** @return BelongsTo<EventRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class);
    }

    /** @return BelongsTo<EventBus, $this> */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(EventBus::class);
    }
}
