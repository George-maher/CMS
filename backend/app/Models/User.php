<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\ResetPasswordNotification;
use App\Traits\AuditableTrait;
use App\Traits\HasPermissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int|null $id
 * @property string|null $member_id
 * @property int|null $church_id
 * @property int|null $church_application_id
 * @property string $application_status
 * @property string $name
 * @property string $email
 * @property Carbon|null $birthday
 * @property string $password
 * @property string $remember_token
 * @property UserRole $role
 * @property int|null $class_year_id
 * @property int|null $class_id
 * @property int|null $invite_id
 * @property int|null $servant_id
 * @property string|null $phone
 * @property string|null $avatar
 * @property string|null $address
 * @property string|null $member_address
 * @property bool $is_active
 * @property int|null $created_by
 * @property string|null $attendance_qr_token
 * @property string|null $email_verification_token
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Church|null $church
 * @property-read ChurchApplication|null $churchApplication
 * @property-read Classe|null $classe
 * @property-read Collection<int, Classe> $classes
 * @property-read QRInvite|null $invite
 * @property-read User|null $createdBy
 * @property-read User|null $servant
 * @property-read Collection<int, User> $assignedMembers
 * @property-read Collection<int, User> $servants
 * @property-read Collection<int, Attendance> $attendances
 * @property-read Collection<int, Attendance> $recordedAttendances
 * @property-read int|null $total_points
 * @property-read int|null $attendance_count
 * @property-read Collection<int, Point> $points
 * @property-read Collection<int, QRInvite> $createdQrInvites
 * @property-read Collection<int, QRInvite> $usedQrInvites
 * @property-read int $total_points
 * @property-read int|null $age
 * @property Carbon|null $viewed_at
 * @property int $assigned_members_count
 * @property-read Collection<int, PersonalAccessToken> $tokens
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\User byRole(\App\Enums\UserRole $role)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\User active()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\User byAttendanceQrToken(string $token)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\User byMemberId(string $memberId)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\User search(string $term)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\User byChurch(?int $churchId = null)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\User approved()
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use AuditableTrait, HasApiTokens, HasFactory, HasPermissions, Notifiable, SoftDeletes;

    protected $fillable = [
        'member_id',
        'church_id',
        'church_application_id',
        'application_status',
        'name',
        'email',
        'birthday',
        'password',
        'role',
        'class_year_id',
        'class_id',
        'invite_id',
        'servant_id',
        'phone',
        'avatar',
        'address',
        'member_address',
        'is_active',
        'created_by',
        'attendance_qr_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'role' => UserRole::class,
            'birthday' => 'date:Y-m-d',
            'application_status' => 'string',
        ];
    }

    /**
     * @return BelongsTo<Church, $this>
     */
    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class);
    }

    /**
     * @return BelongsTo<ChurchApplication, $this>
     */
    public function churchApplication(): BelongsTo
    {
        return $this->belongsTo(ChurchApplication::class);
    }

    /**
     * @return BelongsTo<Classe, $this>
     */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_id');
    }

    /**
     * @return BelongsToMany<Classe, $this, Pivot, 'pivot'>
     */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(Classe::class, 'class_servant', 'user_id', 'class_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<QRInvite, $this>
     */
    public function invite(): BelongsTo
    {
        return $this->belongsTo(QRInvite::class, 'invite_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function servant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'servant_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function assignedMembers(): HasMany
    {
        return $this->hasMany(User::class, 'servant_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function servants(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function recordedAttendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'recorded_by');
    }

    /**
     * @return HasMany<Point, $this>
     */
    public function points(): HasMany
    {
        return $this->hasMany(Point::class);
    }

    /**
     * @return HasMany<QRInvite, $this>
     */
    public function createdQrInvites(): HasMany
    {
        return $this->hasMany(QRInvite::class, 'created_by');
    }

    /**
     * @return HasMany<QRInvite, $this>
     */
    public function usedQrInvites(): HasMany
    {
        return $this->hasMany(QRInvite::class, 'used_by');
    }

    /**
     * @return array<int, int>|null
     */
    public function getServantClassIds(): ?array
    {
        if ($this->isServant()) {
            /** @var array<int, int> $classIds */
            $classIds = $this->classes()->pluck('classes.id')->toArray();
            if (! empty($classIds)) {
                return $classIds;
            }

            return $this->class_id ? [(int) $this->class_id] : null;
        }

        return null;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role === UserRole::PlatformAdmin;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin || $this->role === UserRole::AssistantAdmin;
    }

    public function isAssistantAdmin(): bool
    {
        return $this->role === UserRole::AssistantAdmin;
    }

    public function isServant(): bool
    {
        return $this->role === UserRole::Servant;
    }

    public function isMember(): bool
    {
        return $this->role === UserRole::Member;
    }

    public function isAdminOrAssistantAdmin(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::AssistantAdmin], true);
    }

    public function getTotalPointsAttribute(): int
    {
        return (int) $this->points()->sum('points');
    }

    public function getAgeAttribute(): ?int
    {
        if (! $this->birthday) {
            return null;
        }

        return $this->birthday->age;
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeByRole($query, UserRole $role): Builder
    {
        return $query->where('role', $role->value);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive($query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeByAttendanceQrToken($query, string $token): Builder
    {
        return $query->where('attendance_qr_token', $token);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeByMemberId($query, string $memberId): Builder
    {
        return $query->where('member_id', $memberId);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeSearch($query, string $term): Builder
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('member_id', 'like', "%{$term}%");
        });
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeByChurch($query, ?int $churchId = null): Builder
    {
        $churchId = $churchId ?? auth()->user()?->church_id;
        if ($churchId) {
            return $query->where('users.church_id', $churchId);
        }

        return $query;
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeApproved($query): Builder
    {
        return $query->where('application_status', 'approved');
    }

    public function isApproved(): bool
    {
        return $this->application_status === 'approved';
    }

    public function isPending(): bool
    {
        return $this->application_status === 'pending';
    }

    public function isRejected(): bool
    {
        return $this->application_status === 'rejected';
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function preferredLocale(): string
    {
        return 'en';
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->attendance_qr_token)) {
                $user->attendance_qr_token = self::generateAttendanceQrToken();
            }
        });

        static::created(function (User $user) {
            if ($user->role === UserRole::Member && empty($user->member_id)) {
                $num = str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
                $memberId = 'MBR-'.$num;
                $user->forceFill(['member_id' => $memberId])->saveQuietly();
            }
        });
    }

    public static function generateAttendanceQrToken(): string
    {
        do {
            $token = Str::random(64);
        } while (static::where('attendance_qr_token', $token)->exists());

        return $token;
    }
}
