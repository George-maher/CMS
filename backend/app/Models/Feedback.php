<?php

namespace App\Models;

use App\Enums\FeedbackCategory;
use App\Traits\BelongsToChurch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $message
 * @property FeedbackCategory|null $category
 * @property int|null $class_year_id
 * @property int|null $user_id
 * @property bool $is_anonymous
 * @property bool $is_resolved
 * @property bool $has_new_reply
 * @property int|null $church_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Classe|null $classe
 * @property-read User|null $user
 * @property-read Collection<int, FeedbackReply> $replies
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

    /** @return BelongsTo<Classe, $this> */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'class_year_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<FeedbackReply, $this> */
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
