<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $event_id
 * @property int|null $class_id
 * @property bool $is_all_classes
 * @property int|null $church_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Event|null $event
 * @property-read \App\Models\Classe|null $classe
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

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_id');
    }
}
