<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classe extends Model
{
    use HasFactory, BelongsToChurch;

    protected $fillable = [
        'church_id',
        'stage_id',
        'name',
        'description',
        'display_order',
    ];

    protected $table = 'classes';

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function allUsers(): HasMany
    {
        return $this->hasMany(User::class, 'class_id');
    }

    public function servants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'class_servant', 'class_id', 'user_id')
            ->withTimestamps();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'class_year_id', 'id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'class_year_id', 'id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class, 'class_year_id', 'id');
    }

    public function qrInvites(): HasMany
    {
        return $this->hasMany(QRInvite::class, 'class_year_id', 'id');
    }
}
