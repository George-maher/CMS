<?php

namespace App\Http\Resources;

use App\Models\DailySpiritualRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property-read int $id
 * @property-read int $user_id
 * @property-read int $church_id
 * @property-read string $activity_date
 * @property-read bool $attended_mass
 * @property-read bool $confessed
 * @property-read bool $received_communion
 * @property-read Carbon|null $created_at
 * @property-read Carbon|null $updated_at
 * @property-read User|null $user
 */
class DailySpiritualRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DailySpiritualRecord $record */
        $record = $this->resource;

        $activityDate = $record->activity_date;
        $formattedDate = $activityDate instanceof Carbon
            ? $activityDate->format('Y-m-d')
            : (string) $activityDate;

        return [
            'id' => $record->id,
            'user_id' => $record->user_id,
            'church_id' => $record->church_id,
            'activity_date' => $formattedDate,
            'attended_mass' => $record->attended_mass,
            'confessed' => $record->confessed,
            'received_communion' => $record->received_communion,
            'member' => $this->whenLoaded('user', fn () => [
                'id' => $record->user?->id,
                'name' => $record->user?->name,
                'member_id' => $record->user?->member_id,
                'class_id' => $record->user?->class_id,
            ]),
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }
}
