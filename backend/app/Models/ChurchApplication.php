<?php

namespace App\Models;

use Database\Factories\ChurchApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'admin_notes',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
