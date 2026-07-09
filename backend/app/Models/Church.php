<?php

namespace App\Models;

use App\Traits\AuditableTrait;
use Database\Factories\ChurchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $service_name
 * @property string|null $priest_name
 * @property string|null $main_servant_name
 * @property string|null $priest_phone
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $contact_email
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_suspended
 * @property int|null $deleted_by
 * @property string|null $deletion_type
 * @property \Illuminate\Support\Carbon|null $recoverable_until
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $deletedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Event> $events
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Attendance> $attendances
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Point> $points
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\QRInvite> $qrInvites
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Feedback> $feedback
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\DailyVerse> $dailyVerses
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AttendanceContext> $attendanceContexts
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Stage> $stages
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Classe> $classes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\MembershipRequest> $membershipRequests
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Notification> $notifications
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EventView> $eventViews
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EventTarget> $eventTargets
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AuditLog> $auditLogs
 */
class Church extends Model
{
    /** @use HasFactory<ChurchFactory> */
    use HasFactory, AuditableTrait, SoftDeletes;

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
        return (int) now()->diffInDays($this->recoverable_until, false);
    }

    /** @return BelongsTo<\App\Models\User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /** @return HasMany<\App\Models\User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<\App\Models\Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return HasMany<\App\Models\Attendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** @return HasMany<\App\Models\Point, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(Point::class);
    }

    /** @return HasMany<\App\Models\QRInvite, $this> */
    public function qrInvites(): HasMany
    {
        return $this->hasMany(QRInvite::class);
    }

    /** @return HasMany<\App\Models\Feedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /** @return HasMany<\App\Models\DailyVerse, $this> */
    public function dailyVerses(): HasMany
    {
        return $this->hasMany(DailyVerse::class);
    }

    /** @return HasMany<\App\Models\AttendanceContext, $this> */
    public function attendanceContexts(): HasMany
    {
        return $this->hasMany(AttendanceContext::class);
    }

    /** @return HasMany<\App\Models\Stage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class);
    }

    /** @return HasMany<\App\Models\Classe, $this> */
    public function classes(): HasMany
    {
        return $this->hasMany(Classe::class);
    }

    /** @return HasMany<\App\Models\MembershipRequest, $this> */
    public function membershipRequests(): HasMany
    {
        return $this->hasMany(MembershipRequest::class);
    }

    /** @return HasMany<\App\Models\Notification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /** @return HasMany<\App\Models\EventView, $this> */
    public function eventViews(): HasMany
    {
        return $this->hasMany(EventView::class);
    }

    /** @return HasMany<\App\Models\EventTarget, $this> */
    public function eventTargets(): HasMany
    {
        return $this->hasMany(EventTarget::class);
    }

    /** @return HasMany<\App\Models\AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
