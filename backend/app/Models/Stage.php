<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $church_id
 * @property string $name
 * @property int|null $display_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Classe> $classes
 * @property-read int|null $classes_count
 */
class Stage extends Model
{
    use HasFactory, BelongsToChurch;

    protected $fillable = [
        'church_id',
        'name',
        'display_order',
    ];

    public function classes(): HasMany
    {
        return $this->hasMany(Classe::class, 'stage_id')->orderBy('display_order');
    }
}
