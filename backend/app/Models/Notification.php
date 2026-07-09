<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $church_id
 * @property int $user_id
 * @property int|null $event_id
 * @property int|null $feedback_id
 * @property int|null $points_id
 * @property string $title
 * @property string|null $body
 * @property string $type
 * @property bool $is_read
 * @property \Illuminate\Support\Carbon|null $read_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Event|null $event
 * @property-read \App\Models\Feedback|null $feedback
 * @property-read \App\Models\Point|null $point
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Notification unread()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Notification forUser(int $userId)
 */
class Notification extends Model
{
    use BelongsToChurch;

    protected $fillable = [
        'church_id',
        'user_id',
        'event_id',
        'feedback_id',
        'points_id',
        'title',
        'body',
        'type',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<\App\Models\User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<\App\Models\Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<\App\Models\Feedback, $this> */
    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class);
    }

    /** @return BelongsTo<\App\Models\Point, $this> */
    public function point(): BelongsTo
    {
        return $this->belongsTo(Point::class, 'points_id');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<\App\Models\Notification> $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Notification>
     */
    public function scopeUnread($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_read', false);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<\App\Models\Notification> $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Notification>
     */
    public function scopeForUser($query, int $userId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('user_id', $userId);
    }
}
