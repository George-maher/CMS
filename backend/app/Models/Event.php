<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Traits\AuditableTrait;
use App\Traits\BelongsToChurch;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property EventType $type
 * @property EventStatus $status
 * @property string|null $image
 * @property string|null $description
 * @property Carbon|null $event_date
 * @property Carbon|null $end_date
 * @property string|null $start_time
 * @property string|null $end_time
 * @property int|null $max_capacity
 * @property string|null $location
 * @property string|null $theme
 * @property string|null $target_age_group
 * @property string|null $target_group
 * @property string|null $destination
 * @property string|null $departure_location
 * @property Carbon|null $departure_at
 * @property Carbon|null $return_at
 * @property string|null $transportation_type
 * @property string|null $coordinator_name
 * @property string|null $coordinator_phone
 * @property string $price_per_participant
 * @property int|null $created_by
 * @property bool $is_active
 * @property bool $is_all_classes
 * @property int|null $class_year_id
 * @property int|null $church_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read Classe|null $classe
 * @property-read Collection<int, EventTarget> $targets
 * @property-read Collection<int, EventView> $views
 * @property-read Collection<int, EventSession> $sessions
 * @property-read Collection<int, EventSpeaker> $speakers
 * @property-read Collection<int, EventBus> $buses
 * @property-read Collection<int, EventRegistration> $registrations
 * @property-read Collection<int, EventRoom> $rooms
 * @property-read User|null $responsibleServant
 * @property-read int|null $views_count
 * @property-read int|null $registrations_count
 */
class Event extends Model
{
    use AuditableTrait, BelongsToChurch;

    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'status',
        'image',
        'description',
        'event_date',
        'end_date',
        'start_time',
        'end_time',
        'max_capacity',
        'theme',
        'target_age_group',
        'target_group',
        'destination',
        'departure_location',
        'departure_at',
        'return_at',
        'transportation_type',
        'coordinator_name',
        'coordinator_phone',
        'price_per_participant',
        'location',
        'created_by',
        'responsible_servant_id',
        'is_active',
        'is_all_classes',
        'class_year_id',
        'church_id',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'datetime',
            'end_date' => 'date',
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'departure_at' => 'datetime',
            'return_at' => 'datetime',
            'max_capacity' => 'integer',
            'price_per_participant' => 'decimal:2',
            'is_active' => 'boolean',
            'is_all_classes' => 'boolean',
            'type' => EventType::class,
            'status' => EventStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Classe, $this> */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_year_id');
    }

    /** @return HasMany<EventTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(EventTarget::class);
    }

    /** @return HasMany<EventView, $this> */
    public function views(): HasMany
    {
        return $this->hasMany(EventView::class);
    }

    /** @return HasMany<EventSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class)->orderBy('display_order')->orderBy('starts_at');
    }

    /** @return HasMany<EventSpeaker, $this> */
    public function speakers(): HasMany
    {
        return $this->hasMany(EventSpeaker::class);
    }

    /** @return HasMany<EventBus, $this> */
    public function buses(): HasMany
    {
        return $this->hasMany(EventBus::class);
    }

    /** @return HasMany<EventRegistration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    /** @return HasMany<EventRoom, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(EventRoom::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsibleServant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_servant_id');
    }

    /**
     * Registrations that consume capacity (pending + confirmed).
     *
     * @return HasMany<EventRegistration, $this>
     */
    public function activeRegistrations(): HasMany
    {
        return $this->registrations()->whereIn('status', EventRegistration::activeStatuses());
    }

    /**
     * Whether the event accepts new registrations right now.
     *
     * Canonical rule: an event must be Active (is_active) AND in the open
     * lifecycle state (and not past its date) to accept participation.
     */
    public function isRegistrationOpen(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->status !== EventStatus::Open) {
            return false;
        }

        if ($this->event_date !== null && $this->event_date->endOfDay()->isPast()) {
            return false;
        }

        return true;
    }

    public function hasAvailableCapacity(): bool
    {
        if ($this->max_capacity === null) {
            return true;
        }

        return $this->activeRegistrations()->count() < $this->max_capacity;
    }

    public function registeredCount(): int
    {
        return $this->activeRegistrations()->count();
    }

    public function availableSpaces(): int
    {
        if ($this->max_capacity === null) {
            return -1;
        }

        return max(0, $this->max_capacity - $this->registeredCount());
    }

    public function occupancyPercentage(): int
    {
        if ($this->max_capacity === null || $this->max_capacity === 0) {
            return 0;
        }

        return (int) min(100, round(($this->registeredCount() / $this->max_capacity) * 100));
    }

    public function trackView(int $userId, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        try {
            $this->views()->updateOrCreate(
                ['user_id' => $userId],
                [
                    'church_id' => $this->church_id,
                    'viewed_at' => now(),
                    'created_at' => now(),
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                ]
            );
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }
    }

    public function viewCount(): int
    {
        return $this->views()->count();
    }

    /**
     * Whether this event uses the accommodation workflow (conference or trip).
     */
    public function hasAccommodation(): bool
    {
        return in_array($this->type, [EventType::Conference, EventType::Trip], true);
    }

    /**
     * Total rooms configured for this event.
     */
    public function totalRooms(): int
    {
        return $this->rooms()->count();
    }

    /**
     * Total capacity across all rooms.
     */
    public function totalCapacity(): int
    {
        return (int) $this->rooms()->sum('capacity');
    }

    /**
     * Total member capacity across all rooms.
     */
    public function totalMemberCapacity(): int
    {
        return (int) $this->rooms()->sum('member_capacity');
    }

    /**
     * Total servant-reserved cells.
     */
    public function totalServantCells(): int
    {
        return EventRoomCell::query()
            ->whereHas('room', fn ($q) => $q->where('event_id', $this->id))
            ->where('type', 'servant_reserved')
            ->count();
    }
}
