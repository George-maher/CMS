<?php

namespace App\Models;

use App\Enums\EventAttendanceStatus;
use App\Enums\EventPaymentStatus;
use App\Enums\RegistrationStatus;
use Database\Factories\EventRegistrationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $event_id
 * @property int $user_id
 * @property int|null $registered_by
 * @property int|null $bus_id
 * @property RegistrationStatus $status
 * @property EventPaymentStatus $payment_status
 * @property string $amount_paid
 * @property EventAttendanceStatus $attendance_status
 * @property Carbon|null $checked_in_at
 * @property int|null $checked_in_by
 * @property string $qr_token
 * @property string|null $notes
 * @property string|null $medical_notes
 * @property string|null $booking_with
 * @property string|null $medication_name
 * @property string|null $medication_time
 * @property string|null $rejection_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Event $event
 * @property-read User $user
 * @property-read User|null $registrar
 * @property-read EventBus|null $bus
 * @property-read EventAccommodation|null $accommodation
 * @property-read Collection<int, EventPayment> $payments
 * @property-read int|null $payments_count
 */
class EventRegistration extends Model
{
    /** @use HasFactory<EventRegistrationFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id',
        'user_id',
        'registered_by',
        'bus_id',
        'status',
        'payment_status',
        'amount_paid',
        'attendance_status',
        'checked_in_at',
        'checked_in_by',
        'qr_token',
        'notes',
        'medical_notes',
        'booking_with',
        'medication_name',
        'medication_time',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'payment_status' => EventPaymentStatus::class,
            'attendance_status' => EventAttendanceStatus::class,
            'amount_paid' => 'decimal:2',
            'checked_in_at' => 'datetime',
            'medication_time' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /** @return BelongsTo<EventBus, $this> */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(EventBus::class, 'bus_id');
    }

    /** @return HasOne<EventAccommodation, $this> */
    public function accommodation(): HasOne
    {
        // Inverse of EventAccommodation::registration() — the FK
        // (registration_id) lives on event_accommodations. This was previously
        // belongsTo(), which referenced a nonexistent event_accommodation_id
        // column and crashed every query touching it on PostgreSQL.
        return $this->hasOne(EventAccommodation::class, 'registration_id');
    }

    /** @return HasMany<EventPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(EventPayment::class);
    }

    public static function generateQrToken(): string
    {
        return Str::random(60);
    }

    /**
     * Active (capacity-consuming) statuses.
     *
     * @return array<int, string>
     */
    public static function activeStatuses(): array
    {
        return [
            RegistrationStatus::Pending->value,
            RegistrationStatus::Confirmed->value,
            RegistrationStatus::Approved->value,
        ];
    }

    /**
     * Atomically increment the amount paid and refresh the payment status.
     */
    public function addPaidAmount(float|int|string $amount, ?float $price): void
    {
        DB::table('event_registrations')
            ->where('id', $this->id)
            ->update([
                'amount_paid' => DB::raw('amount_paid + '.number_format((float) $amount, 2, '.', '')),
                'updated_at' => now(),
            ]);

        $this->refresh();

        $this->refreshPaymentStatus($price);
    }

    public function refreshPaymentStatus(?float $price): void
    {
        $paid = (float) $this->amount_paid;

        if ($this->payment_status === EventPaymentStatus::Refunded) {
            return;
        }

        $required = max(0.0, (float) $price);

        if ($required > 0 && $paid >= $required) {
            $this->payment_status = EventPaymentStatus::Paid;
        } elseif ($paid > 0) {
            $this->payment_status = EventPaymentStatus::PartiallyPaid;
        } else {
            $this->payment_status = EventPaymentStatus::Unpaid;
        }

        $this->save();
    }
}
