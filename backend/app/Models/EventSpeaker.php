<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property string $name
 * @property string|null $title
 * @property string|null $bio
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Event $event
 */
class EventSpeaker extends Model
{
    protected $fillable = [
        'event_id',
        'name',
        'title',
        'bio',
    ];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
