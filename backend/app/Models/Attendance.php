<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'points_earned',
        'church_id',
    ];

    protected function casts(): array
    {
        return [
            'attended_at' => 'datetime',
            'points_earned' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_year_id');
    }

    public function qrInvite(): BelongsTo
    {
        return $this->belongsTo(QRInvite::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function attendanceContext(): BelongsTo
    {
        return $this->belongsTo(AttendanceContext::class);
    }
}
