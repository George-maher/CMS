<?php

namespace App\Services;

use App\Contracts\DailySpiritualRecordServiceInterface;
use App\Models\DailySpiritualRecord;
use Illuminate\Database\Eloquent\Collection;

class DailySpiritualRecordService implements DailySpiritualRecordServiceInterface
{
    /** @return array<string, mixed> */
    public function listForMember(int $memberId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = DailySpiritualRecord::where('user_id', $memberId)
            ->orderByDesc('activity_date');

        if ($dateFrom !== null && $dateFrom !== '') {
            $query->where('activity_date', '>=', $dateFrom);
        }
        if ($dateTo !== null && $dateTo !== '') {
            $query->where('activity_date', '<=', $dateTo);
        }

        /** @var Collection<int, DailySpiritualRecord> $records */
        $records = $query->get();

        return [
            'records' => $records,
            'member_id' => $memberId,
        ];
    }

    public function getByDate(int $memberId, string $activityDate): ?DailySpiritualRecord
    {
        return DailySpiritualRecord::where('user_id', $memberId)
            ->where('activity_date', $activityDate)
            ->first();
    }

    /** @return array<string, mixed> */
    public function storeOrUpdate(int $memberId, array $data): array
    {
        $activityDate = $data['activity_date'];

        $record = DailySpiritualRecord::where('user_id', $memberId)
            ->where('activity_date', $activityDate)
            ->first();

        $wasCreated = $record === null;

        if ($record === null) {
            $record = DailySpiritualRecord::create([
                'user_id' => $memberId,
                'activity_date' => $activityDate,
                'attended_mass' => $data['attended_mass'] ?? false,
                'confessed' => $data['confessed'] ?? false,
                'received_communion' => $data['received_communion'] ?? false,
            ]);
        } else {
            $record->update([
                'attended_mass' => $data['attended_mass'] ?? $record->attended_mass,
                'confessed' => $data['confessed'] ?? $record->confessed,
                'received_communion' => $data['received_communion'] ?? $record->received_communion,
            ]);
        }

        return [
            'record' => $record->fresh(),
            'was_created' => $wasCreated,
        ];
    }

    public function delete(int $memberId, string $activityDate): bool
    {
        $record = DailySpiritualRecord::where('user_id', $memberId)
            ->where('activity_date', $activityDate)
            ->first();

        if ($record === null) {
            return false;
        }

        return (bool) $record->delete();
    }
}
