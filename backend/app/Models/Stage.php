<?php

namespace App\Models;

use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
