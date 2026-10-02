<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Event|null $event
 * @property-read Feedback|null $feedback
 * @property-read Point|null $point
 *
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Feedback, $this> */
    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class);
    }

    /** @return BelongsTo<Point, $this> */
    public function point(): BelongsTo
    {
        return $this->belongsTo(Point::class, 'points_id');
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeUnread($query): Builder
    {
        return $query->where('is_read', false);
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeForUser($query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
