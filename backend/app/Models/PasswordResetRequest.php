<?php

namespace App\Models;

use App\Enums\PasswordResetRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property string|null $notes
 * @property PasswordResetRequestStatus $status
 * @property string|null $token
 * @property string|null $rejection_reason
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read User|null $reviewer
 */
class PasswordResetRequest extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'notes',
        'status',
        'token',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'token_expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PasswordResetRequestStatus::class,
            'reviewed_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isValidToken(): bool
    {
        return $this->token !== null
            && $this->token_expires_at !== null
            && $this->token_expires_at->isFuture()
            && $this->used_at === null;
    }

    public function isPending(): bool
    {
        return $this->status === PasswordResetRequestStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === PasswordResetRequestStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === PasswordResetRequestStatus::Rejected;
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (static::where('token', $token)->exists());

        return $token;
    }

    public function markAsUsed(): void
    {
        $this->update([
            'used_at' => now(),
            'token' => null,
        ]);
    }
}
