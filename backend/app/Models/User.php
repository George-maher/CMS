<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Traits\AuditableTrait;
use App\Traits\HasPermissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasApiTokens, Notifiable, SoftDeletes, AuditableTrait, HasPermissions;

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

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class);
    }

    public function churchApplication(): BelongsTo
    {
        return $this->belongsTo(ChurchApplication::class);
    }

    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_id');
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(Classe::class, 'class_servant', 'user_id', 'class_id')
            ->withTimestamps();
    }

    public function invite(): BelongsTo
    {
        return $this->belongsTo(QRInvite::class, 'invite_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function servant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'servant_id');
    }

    public function assignedMembers(): HasMany
    {
        return $this->hasMany(User::class, 'servant_id');
    }

    public function servants(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function recordedAttendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'recorded_by');
    }

    public function points(): HasMany
    {
        return $this->hasMany(Point::class);
    }

    public function createdQrInvites(): HasMany
    {
        return $this->hasMany(QRInvite::class, 'created_by');
    }

    public function usedQrInvites(): HasMany
    {
        return $this->hasMany(QRInvite::class, 'used_by');
    }

    public function getServantClassIds(): ?array
    {
        if ($this->isServant()) {
            $classIds = $this->classes()->pluck('classes.id')->toArray();
            if (!empty($classIds)) {
                return $classIds;
            }
            return $this->class_id ? [$this->class_id] : null;
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
        if (!$this->birthday) {
            return null;
        }
        return $this->birthday->age;
    }

    public function scopeByRole($query, UserRole $role)
    {
        return $query->where('role', $role->value);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByAttendanceQrToken($query, string $token)
    {
        return $query->where('attendance_qr_token', $token);
    }

    public function scopeByMemberId($query, string $memberId)
    {
        return $query->where('member_id', $memberId);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('member_id', 'like', "%{$term}%");
        });
    }

    public function scopeByChurch($query, ?int $churchId = null)
    {
        $churchId = $churchId ?? auth()->user()?->church_id;
        if ($churchId) {
            return $query->where('users.church_id', $churchId);
        }
        return $query;
    }

    public function scopeApproved($query)
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
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
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
                $seq = DB::select("SELECT nextval('users_member_id_seq') AS seq");
                $num = str_pad((int) ($seq[0]->seq ?? $user->id), 6, '0', STR_PAD_LEFT);
                $memberId = 'MBR-' . $num;
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
