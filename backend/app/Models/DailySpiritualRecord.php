<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $church_id
 * @property string $activity_date
 * @property bool $attended_mass
 * @property bool $confessed
 * @property bool $received_communion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Church|null $church
 */
class DailySpiritualRecord extends Model
{
    use BelongsToChurch;

    protected $fillable = [
        'user_id',
        'church_id',
        'activity_date',
        'attended_mass',
        'confessed',
        'received_communion',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date:Y-m-d',
            'attended_mass' => 'boolean',
            'confessed' => 'boolean',
            'received_communion' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Church, $this> */
    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class);
    }
}
