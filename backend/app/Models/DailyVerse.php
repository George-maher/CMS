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
 * @property string $verse_text
 * @property string $reference
 * @property int|null $created_by
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 *
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<DailyVerse>  $query
     * @return Builder<DailyVerse>
     */
    public function scopeActive($query): Builder
    {
        return $query->where('is_active', true);
    }
}
