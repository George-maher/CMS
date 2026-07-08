<?php

namespace App\Models;

use Database\Factories\ChurchApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $church_name
 * @property string|null $service_name
 * @property string|null $priest_name
 * @property string|null $main_servant_name
 * @property string|null $priest_phone
 * @property string|null $phone
 * @property string|null $address
 * @property string $contact_email
 * @property string|null $description
 * @property string|null $front_id_path
 * @property string|null $back_id_path
 * @property string|null $church_permission_doc_path
 * @property string $status
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property \Illuminate\Support\Carbon|null $rejected_at
 * @property string|null $admin_notes
 * @property string|null $rejection_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $reviewer
 */
class ChurchApplication extends Model
{
    /** @use HasFactory<ChurchApplicationFactory> */
    use HasFactory;

    protected $fillable = [
        'church_name',
        'service_name',
        'priest_name',
        'main_servant_name',
        'priest_phone',
        'phone',
        'address',
        'contact_email',
        'description',
        'front_id_path',
        'back_id_path',
        'church_permission_doc_path',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejected_at',
        'admin_notes',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
