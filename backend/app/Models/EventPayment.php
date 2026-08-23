<?php

namespace App\Models;

use App\Enums\EventPaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $registration_id
 * @property float $amount
 * @property EventPaymentMethod $method
 * @property Carbon $paid_at
 * @property string|null $note
 * @property int|null $recorded_by
 * @property bool $refunded
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EventRegistration $registration
 * @property-read User|null $recorder
 */
class EventPayment extends Model
{
    protected $fillable = [
        'registration_id',
        'amount',
        'method',
        'paid_at',
        'note',
        'recorded_by',
        'refunded',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => EventPaymentMethod::class,
            'paid_at' => 'datetime',
            'refunded' => 'boolean',
        ];
    }

    /** @return BelongsTo<EventRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
