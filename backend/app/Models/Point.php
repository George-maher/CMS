<?php

namespace App\Models;

use App\Enums\PointType;
use App\Enums\UserRole;
use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $points
 * @property PointType $type
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $added_by
 * @property string|null $description
 * @property int|null $church_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Model|\Eloquent $reference
 * @property-read User|null $addedBy
 */
class Point extends Model
{
    use BelongsToChurch;

    protected $fillable = [
        'user_id',
        'points',
        'type',
        'reference_type',
        'reference_id',
        'added_by',
        'description',
        'church_id',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'type' => PointType::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Point $point) {
            $user = User::find($point->user_id);
            if (! $user || $user->role !== UserRole::Member) {
                throw new \RuntimeException('Points can only be assigned to members.');
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
