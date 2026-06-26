<?php

namespace App\Models;

use App\Enums\QRInviteType;
use App\Traits\AuditableTrait;
use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QRInvite extends Model
{
    use BelongsToChurch, AuditableTrait;

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_id');
    }

    public function classeYear(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_year_id');
    }

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
        $user = \App\Models\User::with('classe.stage')->find($userId);

        $userEntry = [
            'id' => $userId,
            'name' => $user?->name ?? 'Unknown',
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
            'use_count' => \Illuminate\Support\Facades\DB::raw('use_count + 1'),
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

    public function scopeValid($query)
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
