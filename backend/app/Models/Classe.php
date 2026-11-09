<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Database\Factories\ClasseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $church_id
 * @property int|null $stage_id
 * @property int|null $class_secretary_id
 * @property string $name
 * @property string|null $description
 * @property int|null $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Stage|null $stage
 * @property-read User|null $secretary
 * @property-read Collection<int, User> $allUsers
 * @property-read Collection<int, User> $servants
 * @property-read Collection<int, Attendance> $attendances
 * @property-read Collection<int, Event> $events
 * @property-read Collection<int, Feedback> $feedback
 * @property-read Collection<int, QRInvite> $qrInvites
 * @property-read int|null $member_count
 * @property-read int|null $servant_count
 */
class Classe extends Model
{
    /** @use HasFactory<ClasseFactory> */
    use BelongsToChurch, HasFactory;

    protected $fillable = [
        'church_id',
        'stage_id',
        'class_secretary_id',
        'name',
        'description',
        'display_order',
    ];

    protected $table = 'classes';

    /** @return BelongsTo<Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /**
     * The single active class secretary (أمين الفصل).
     *
     * The assignment is a class-level responsibility stored on the class row,
     * not a global role: the user keeps their existing role (normally
     * `servant`) and only gains read access to this class through
     * User::getServantClassIds().
     *
     * @return BelongsTo<User, $this>
     */
    public function secretary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'class_secretary_id');
    }

    /** @return HasMany<User, $this> */
    public function allUsers(): HasMany
    {
        return $this->hasMany(User::class, 'class_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function servants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'class_servant', 'class_id', 'user_id')
            ->withTimestamps();
    }

    /** @return HasMany<Attendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'class_year_id', 'id');
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'class_year_id', 'id');
    }

    /** @return HasMany<Feedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class, 'class_year_id', 'id');
    }

    /** @return HasMany<QRInvite, $this> */
    public function qrInvites(): HasMany
    {
        return $this->hasMany(QRInvite::class, 'class_year_id', 'id');
    }
}
