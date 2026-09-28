<?php

namespace App\Models;

use App\Enums\FeedbackCategory;
use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $message
 * @property \App\Enums\FeedbackCategory|null $category
 * @property int|null $class_year_id
 * @property int|null $user_id
 * @property bool $is_anonymous
 * @property bool $is_resolved
 * @property bool $has_new_reply
 * @property int|null $church_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Classe|null $classe
 * @property-read \App\Models\User|null $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FeedbackReply> $replies
 */
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

    /** @return BelongsTo<\App\Models\Classe, $this> */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_year_id');
    }

    /** @return BelongsTo<\App\Models\User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<\App\Models\FeedbackReply, $this> */
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
