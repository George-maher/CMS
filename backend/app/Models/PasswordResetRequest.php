<?php

namespace App\Models;

use App\Enums\PasswordResetRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property string|null $notes
 * @property PasswordResetRequestStatus $status
 * @property string|null $rejection_reason
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
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
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PasswordResetRequestStatus::class,
            'reviewed_at' => 'datetime',
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

    public function isCompleted(): bool
    {
        return $this->status === PasswordResetRequestStatus::Completed;
    }
}
