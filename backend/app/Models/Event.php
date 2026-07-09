<?php

namespace App\Models;

use App\Enums\EventType;
use App\Traits\AuditableTrait;
use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property EventType $type
 * @property string|null $image
 * @property string|null $description
 * @property Carbon|null $event_date
 * @property string|null $location
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
 * @property-read int|null $views_count
 */
class Event extends Model
{
    use AuditableTrait, BelongsToChurch;

    protected $fillable = [
        'name',
        'type',
        'image',
        'description',
        'event_date',
        'location',
        'created_by',
        'is_active',
        'is_all_classes',
        'class_year_id',
        'church_id',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'datetime',
            'is_active' => 'boolean',
            'is_all_classes' => 'boolean',
            'type' => EventType::class,
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
}
