<?php

namespace App\Models;

use App\Enums\EventType;
use App\Traits\AuditableTrait;
use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use BelongsToChurch, AuditableTrait;

    protected $fillable = [
        'name',
        'type',
        'image',
        'description',
        'event_date',
        'location',
        'created_by',
        'is_active',
        'is_all_classes',
        'class_year_id',
        'church_id',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'datetime',
            'is_active' => 'boolean',
            'is_all_classes' => 'boolean',
            'type' => EventType::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_year_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(EventTarget::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(EventView::class);
    }

    public function trackView(int $userId, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        try {
            $this->views()->updateOrCreate(
                ['user_id' => $userId],
                [
                    'church_id' => $this->church_id,
                    'viewed_at' => now(),
                    'created_at' => now(),
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                ]
            );
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }
    }

    public function viewCount(): int
    {
        return $this->views()->count();
    }
}
