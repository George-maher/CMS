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
 * @property string $bus_number
 * @property int $capacity
 * @property string|null $driver_name
 * @property string|null $coordinator_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Event $event
 * @property-read Collection<int, EventRegistration> $registrations
 * @property-read int|null $registrations_count
 */
class EventBus extends Model
{
    protected $fillable = [
        'event_id',
        'bus_number',
        'capacity',
        'driver_name',
        'coordinator_name',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<EventRegistration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'bus_id');
    }

    public function assignedCount(): int
    {
        return $this->registrations()->count();
    }

    public function availableSeats(): int
    {
        return max(0, $this->capacity - $this->assignedCount());
    }
}
