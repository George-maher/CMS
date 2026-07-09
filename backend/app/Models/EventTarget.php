<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property int|null $class_id
 * @property bool $is_all_classes
 * @property int|null $church_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Event|null $event
 * @property-read Classe|null $classe
 */
class EventTarget extends Model
{
    use BelongsToChurch;

    protected $fillable = [
        'event_id',
        'class_id',
        'is_all_classes',
        'church_id',
    ];

    protected function casts(): array
    {
        return [
            'is_all_classes' => 'boolean',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Classe, $this> */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_id');
    }
}
