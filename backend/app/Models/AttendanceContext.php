<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Database\Factories\AttendanceContextFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $church_id
 * @property string $name
 * @property string|null $name_ar
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $creator
 * @property-read \App\Models\User|null $updater
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AttendanceContext active()
 */
class AttendanceContext extends Model
{
    /** @use HasFactory<AttendanceContextFactory> */
    use HasFactory, BelongsToChurch;

    protected $fillable = [
        'church_id',
        'name',
        'name_ar',
        'slug',
        'description',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AttendanceContext $context) {
            if (empty($context->slug)) {
                $context->slug = Str::slug($context->name);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<\App\Models\AttendanceContext> $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\AttendanceContext>
     */
    public function scopeActive($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }
}
