<?php

namespace App\Models;

use App\Enums\FeedbackCategory;
use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feedback extends Model
{
    use BelongsToChurch;

    protected $table = 'feedback';

    protected $fillable = [
        'message',
        'category',
        'class_year_id',
        'user_id',
        'is_anonymous',
        'is_resolved',
        'has_new_reply',
        'church_id',
    ];

    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_year_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(FeedbackReply::class);
    }

    protected function casts(): array
    {
        return [
            'is_resolved' => 'boolean',
            'is_anonymous' => 'boolean',
            'has_new_reply' => 'boolean',
            'category' => FeedbackCategory::class,
        ];
    }
}
