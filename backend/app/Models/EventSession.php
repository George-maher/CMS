<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property string $title
 * @property string|null $description
 * @property string|null $speaker_name
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Event $event
 */
class EventSession extends Model
{
    protected $fillable = [
        'event_id',
        'title',
        'description',
        'speaker_name',
        'starts_at',
        'ends_at',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'display_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
