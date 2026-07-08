<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $church_id
 * @property int $event_id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $viewed_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read \App\Models\Event|null $event
 * @property-read \App\Models\User|null $user
 */
class EventView extends Model
{
    use BelongsToChurch;

    public $timestamps = false;

    protected $fillable = [
        'church_id',
        'event_id',
        'user_id',
        'viewed_at',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
