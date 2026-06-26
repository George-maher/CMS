<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
