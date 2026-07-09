<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $recorded_by
 * @property int|null $class_year_id
 * @property int|null $qr_invite_id
 * @property int|null $event_id
 * @property int|null $attendance_context_id
 * @property string|null $method
 * @property \Illuminate\Support\Carbon $attended_at
 * @property int $points_earned
 * @property int|null $church_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read User|null $recorder
 * @property-read Classe|null $classe
 * @property-read QRInvite|null $qrInvite
 * @property-read Event|null $event
 * @property-read AttendanceContext|null $attendanceContext
 * @property-read string|null $last_attended_at
 * @property-read int $attended_days
 * @property-read int $count
 */
class Attendance extends Model
{
    use BelongsToChurch;

    protected $fillable = [
        'user_id',
        'recorded_by',
        'class_year_id',
        'qr_invite_id',
        'event_id',
        'attendance_context_id',
        'method',
        'attended_at',
        'attended_date',
        'points_earned',
        'church_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Attendance $attendance) {
            if ($attendance->attended_at && ! $attendance->attended_date) {
                $attendance->attended_date = $attendance->attended_at instanceof Carbon
                    ? $attendance->attended_at->toDateString()
                    : $attendance->attended_at;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'attended_at' => 'datetime',
            'attended_date' => 'date:Y-m-d',
            'points_earned' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<Classe, $this> */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_year_id');
    }

    /** @return BelongsTo<QRInvite, $this> */
    public function qrInvite(): BelongsTo
    {
        return $this->belongsTo(QRInvite::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<AttendanceContext, $this> */
    public function attendanceContext(): BelongsTo
    {
        return $this->belongsTo(AttendanceContext::class);
    }
}
