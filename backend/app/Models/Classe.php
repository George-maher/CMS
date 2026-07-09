<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $church_id
 * @property int|null $stage_id
 * @property string $name
 * @property string|null $description
 * @property int|null $display_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Stage|null $stage
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $allUsers
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $servants
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Attendance> $attendances
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Event> $events
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Feedback> $feedback
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\QRInvite> $qrInvites
 * @property-read int|null $member_count
 * @property-read int|null $servant_count
 */
class Classe extends Model
{
    /** @use HasFactory<\Database\Factories\ClasseFactory> */
    use HasFactory, BelongsToChurch;

    protected $fillable = [
        'church_id',
        'stage_id',
        'name',
        'description',
        'display_order',
    ];

    protected $table = 'classes';

    /** @return BelongsTo<\App\Models\Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /** @return HasMany<\App\Models\User, $this> */
    public function allUsers(): HasMany
    {
        return $this->hasMany(User::class, 'class_id');
    }

    /** @return BelongsToMany<\App\Models\User, $this> */
    public function servants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'class_servant', 'class_id', 'user_id')
            ->withTimestamps();
    }

    /** @return HasMany<\App\Models\Attendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'class_year_id', 'id');
    }

    /** @return HasMany<\App\Models\Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'class_year_id', 'id');
    }

    /** @return HasMany<\App\Models\Feedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class, 'class_year_id', 'id');
    }

    /** @return HasMany<\App\Models\QRInvite, $this> */
    public function qrInvites(): HasMany
    {
        return $this->hasMany(QRInvite::class, 'class_year_id', 'id');
    }
}
