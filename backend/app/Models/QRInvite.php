<?php

namespace App\Models;

use App\Enums\QRInviteType;
use App\Traits\AuditableTrait;
use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property QRInviteType $type
 * @property string $token
 * @property int|null $created_by
 * @property int|null $class_id
 * @property int|null $class_year_id
 * @property int|null $attendance_context_id
 * @property int|null $used_by
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property bool $is_revoked
 * @property bool $is_single_use
 * @property int|null $max_uses
 * @property int $use_count
 * @property array<int, array{id: int, name: string, role: string|null, phone: string|null, member_id: string|null, class_id: int|null, class_name: string|null, stage_name: string|null, used_at: string}>|null $used_by_users
 * @property int|null $church_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read User|null $usedBy
 * @property-read Classe|null $classe
 * @property-read Classe|null $classeYear
 * @property-read AttendanceContext|null $attendanceContext
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\QRInvite valid()
 */
class QRInvite extends Model
{
    use AuditableTrait, BelongsToChurch;

    protected $table = 'qr_invites';

    protected $fillable = [
        'type',
        'token',
        'created_by',
        'class_id',
        'class_year_id',
        'attendance_context_id',
        'used_by',
        'expires_at',
        'used_at',
        'is_revoked',
        'is_single_use',
        'max_uses',
        'use_count',
        'used_by_users',
        'church_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => QRInviteType::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'is_revoked' => 'boolean',
            'is_single_use' => 'boolean',
            'max_uses' => 'integer',
            'use_count' => 'integer',
            'used_by_users' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    /**
     * @return BelongsTo<Classe, $this>
     */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_id');
    }

    /**
     * @return BelongsTo<Classe, $this>
     */
    public function classeYear(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_year_id');
    }

    /**
     * @return BelongsTo<AttendanceContext, $this>
     */
    public function attendanceContext(): BelongsTo
    {
        return $this->belongsTo(AttendanceContext::class, 'attendance_context_id');
    }

    public function isValid(): bool
    {
        if ($this->is_revoked || $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses !== null && $this->use_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function markAsUsed(int $userId): bool
    {
        $user = User::with('classe.stage')->find($userId);

        $userEntry = [
            'id' => $userId,
            'name' => $user->name ?? 'Unknown',
            'role' => $user?->role?->value,
            'phone' => $user?->phone,
            'member_id' => $user?->member_id,
            'class_id' => $user?->class_id,
            'class_name' => $user?->classe?->name,
            'stage_name' => $user?->classe?->stage?->name,
            'used_at' => now()->toISOString(),
        ];

        $existingUsers = $this->used_by_users ?? [];
        $existingUsers[] = $userEntry;

        $newCount = $this->use_count + 1;
        $isFinalUse = $this->max_uses !== null && $newCount >= $this->max_uses;

        $updates = [
            'use_count' => DB::raw('use_count + 1'),
            'used_by_users' => json_encode($existingUsers),
        ];

        if ($isFinalUse) {
            $updates['used_by'] = $userId;
            $updates['used_at'] = now();
        }

        // Optimistic lock: only apply if use_count hasn't moved since we loaded it
        $query = static::where('id', $this->id)
            ->where('use_count', $this->use_count);

        if ($isFinalUse) {
            $query->whereNull('used_at');
        }

        $affected = $query->update($updates);

        if ($affected > 0) {
            $this->refresh();
        }

        return $affected > 0;
    }

    /**
     * @param  Builder<QRInvite>  $query
     * @return Builder<QRInvite>
     */
    public function scopeValid($query): Builder
    {
        return $query
            ->where('is_revoked', false)
            ->where('expires_at', '>', now())
            ->where(function ($q) {
                $q->whereNull('max_uses')
                    ->orWhereColumn('use_count', '<', 'max_uses');
            });
    }
}
