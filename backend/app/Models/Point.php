<?php

namespace App\Models;

use App\Enums\PointType;
use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $points
 * @property \App\Enums\PointType $type
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $added_by
 * @property string|null $description
 * @property int|null $church_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \Illuminate\Database\Eloquent\Model|\Eloquent $reference
 * @property-read \App\Models\User|null $addedBy
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
