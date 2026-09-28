<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $church_id
 * @property string $verse_text
 * @property string $reference
 * @property int|null $created_by
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $creator
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DailyVerse active()
 */
class DailyVerse extends Model
{
    use BelongsToChurch;

    protected $fillable = [
        'verse_text',
        'reference',
        'created_by',
        'is_active',
        'church_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<\App\Models\User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<\App\Models\DailyVerse> $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\DailyVerse>
     */
    public function scopeActive($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }
}
