<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $church_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property Carbon|null $birthday
 * @property string|null $address
 * @property string|null $preferred_role
 * @property string $status
 * @property string|null $notes
 * @property string|null $rejection_reason
 * @property string|null $file_url
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Church|null $church
 * @property-read User|null $reviewer
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MembershipRequest pending()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MembershipRequest forChurch(int $churchId)
 */
class MembershipRequest extends Model
{
    use BelongsToChurch;

    protected $fillable = [
        'church_id',
        'name',
        'email',
        'phone',
        'birthday',
        'address',
        'preferred_role',
        'status',
        'notes',
        'rejection_reason',
        'file_url',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Church, $this> */
    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<MembershipRequest>  $query
     * @return Builder<MembershipRequest>
     */
    public function scopePending($query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * @param  Builder<MembershipRequest>  $query
     * @return Builder<MembershipRequest>
     */
    public function scopeForChurch($query, int $churchId): Builder
    {
        return $query->where('church_id', $churchId);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function approve(User $admin): User
    {
        $this->update([
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $tmpPassword = Str::random(40);

        $user = User::create([
            'church_id' => $this->church_id,
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($tmpPassword),
            'role' => $this->preferred_role === 'servant' ? UserRole::Servant : UserRole::Member,
            'application_status' => 'approved',
            'is_active' => true,
            'phone' => $this->phone,
            'birthday' => $this->birthday,
            'address' => $this->address,
        ]);

        return $user;
    }

    public function reject(User $admin, string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);
    }
}
