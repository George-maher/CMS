<?php

namespace App\Models;

use App\Enums\PointType;
use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
