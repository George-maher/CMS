<?php

namespace App\Models;

use App\Enums\ProfileUpdateRequestStatus;
use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $church_id
 * @property int|null $reviewer_id
 * @property ProfileUpdateRequestStatus $status
 * @property array<string, mixed> $old_values
 * @property array<string, mixed> $new_values
 * @property string|null $rejection_reason
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read User|null $reviewer
 * @property-read Church|null $church
 */
class ProfileUpdateRequest extends Model
{
    use BelongsToChurch;

    protected $fillable = [
        'user_id',
        'church_id',
        'reviewer_id',
        'status',
        'old_values',
        'new_values',
        'rejection_reason',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProfileUpdateRequestStatus::class,
            'old_values' => 'array',
            'new_values' => 'array',
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
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isPending(): bool
    {
        return $this->status === ProfileUpdateRequestStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === ProfileUpdateRequestStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === ProfileUpdateRequestStatus::Rejected;
    }

    /**
     * Get the fields that were changed between old and new values.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function getChangesSummary(): array
    {
        $changes = [];
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];

        foreach ($new as $field => $newValue) {
            $oldValue = $old[$field] ?? null;
            if ($oldValue !== $newValue) {
                $changes[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }
}
