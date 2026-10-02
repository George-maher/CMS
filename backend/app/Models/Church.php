<?php

namespace App\Models;

use App\Traits\AuditableTrait;
use Database\Factories\ChurchFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
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
 * @property Carbon|null $recoverable_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $deletedBy
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, Event> $events
 * @property-read Collection<int, Attendance> $attendances
 * @property-read Collection<int, Point> $points
 * @property-read Collection<int, QRInvite> $qrInvites
 * @property-read Collection<int, Feedback> $feedback
 * @property-read Collection<int, DailyVerse> $dailyVerses
 * @property-read Collection<int, AttendanceContext> $attendanceContexts
 * @property-read Collection<int, Stage> $stages
 * @property-read Collection<int, Classe> $classes
 * @property-read Collection<int, MembershipRequest> $membershipRequests
 * @property-read Collection<int, Notification> $notifications
 * @property-read Collection<int, EventView> $eventViews
 * @property-read Collection<int, EventTarget> $eventTargets
 * @property-read Collection<int, AuditLog> $auditLogs
 */
class Church extends Model
{
    /** @use HasFactory<ChurchFactory> */
    use AuditableTrait, HasFactory, SoftDeletes;

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
                $church->slug = Str::slug($church->name).'-'.Str::random(6);
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
        if (! $this->trashed()) {
            return false;
        }
        if (! $this->recoverable_until) {
            return false;
        }

        return now()->lessThan($this->recoverable_until);
    }

    public function daysUntilPurge(): ?int
    {
        if (! $this->recoverable_until) {
            return null;
        }

        return (int) now()->diffInDays($this->recoverable_until, false);
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return HasMany<Attendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** @return HasMany<Point, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(Point::class);
    }

    /** @return HasMany<QRInvite, $this> */
    public function qrInvites(): HasMany
    {
        return $this->hasMany(QRInvite::class);
    }

    /** @return HasMany<Feedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /** @return HasMany<DailyVerse, $this> */
    public function dailyVerses(): HasMany
    {
        return $this->hasMany(DailyVerse::class);
    }

    /** @return HasMany<AttendanceContext, $this> */
    public function attendanceContexts(): HasMany
    {
        return $this->hasMany(AttendanceContext::class);
    }

    /** @return HasMany<Stage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class);
    }

    /** @return HasMany<Classe, $this> */
    public function classes(): HasMany
    {
        return $this->hasMany(Classe::class);
    }

    /** @return HasMany<MembershipRequest, $this> */
    public function membershipRequests(): HasMany
    {
        return $this->hasMany(MembershipRequest::class);
    }

    /** @return HasMany<Notification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /** @return HasMany<EventView, $this> */
    public function eventViews(): HasMany
    {
        return $this->hasMany(EventView::class);
    }

    /** @return HasMany<EventTarget, $this> */
    public function eventTargets(): HasMany
    {
        return $this->hasMany(EventTarget::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
