<?php

namespace App\Models;

use App\Traits\AuditableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Church extends Model
{
    use AuditableTrait, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'service_name',
        'priest_name',
        'main_servant_name',
        'priest_phone',
        'phone',
        'address',
        'contact_email',
        'description',
        'is_active',
        'is_suspended',
        'deleted_by',
        'deletion_type',
        'recoverable_until',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_suspended' => 'boolean',
            'deleted_at' => 'datetime',
            'recoverable_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Church $church) {
            if (empty($church->slug)) {
                $church->slug = Str::slug($church->name) . '-' . Str::random(6);
            }
        });

        static::created(function (Church $church) {
            $defaultContexts = [
                ['name' => 'Sunday School', 'name_ar' => 'مدارس الأحد', 'slug' => 'sunday-school', 'description' => 'Regular Sunday school sessions for all classes'],
                ['name' => 'Holiday', 'name_ar' => 'العطلة', 'slug' => 'holiday', 'description' => 'Holiday and vacation programs'],
                ['name' => 'Tasbeha', 'name_ar' => 'تسبحة', 'slug' => 'tasbeha', 'description' => 'Evening praise and prayer gatherings'],
                ['name' => 'Mass', 'name_ar' => 'قداس', 'slug' => 'mass', 'description' => 'Divine liturgy and masses'],
                ['name' => 'Trip', 'name_ar' => 'رحلة', 'slug' => 'trip', 'description' => 'Church-organized trips and excursions'],
                ['name' => 'Spiritual Day', 'name_ar' => 'يوم روحي', 'slug' => 'spiritual-day', 'description' => 'Spiritual retreats and special spiritual days'],
            ];

            foreach ($defaultContexts as $ctx) {
                $church->attendanceContexts()->create([
                    'name' => $ctx['name'],
                    'name_ar' => $ctx['name_ar'],
                    'slug' => $ctx['slug'],
                    'description' => $ctx['description'],
                    'is_active' => true,
                ]);
            }
        });
    }

    public function isRecoverable(): bool
    {
        if (!$this->trashed()) {
            return false;
        }
        if (!$this->recoverable_until) {
            return false;
        }
        return now()->lessThan($this->recoverable_until);
    }

    public function daysUntilPurge(): ?int
    {
        if (!$this->recoverable_until) {
            return null;
        }
        return now()->diffInDays($this->recoverable_until, false);
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(Point::class);
    }

    public function qrInvites(): HasMany
    {
        return $this->hasMany(QRInvite::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function dailyVerses(): HasMany
    {
        return $this->hasMany(DailyVerse::class);
    }

    public function attendanceContexts(): HasMany
    {
        return $this->hasMany(AttendanceContext::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(Classe::class);
    }

    public function membershipRequests(): HasMany
    {
        return $this->hasMany(MembershipRequest::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function eventViews(): HasMany
    {
        return $this->hasMany(EventView::class);
    }

    public function eventTargets(): HasMany
    {
        return $this->hasMany(EventTarget::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
