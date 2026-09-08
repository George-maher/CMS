<?php

namespace App\Contracts;

use App\Models\DailySpiritualRecord;

interface DailySpiritualRecordServiceInterface
{
    /** @return array<string, mixed> */
    public function listForMember(int $memberId, ?string $dateFrom = null, ?string $dateTo = null): array;

    public function getByDate(int $memberId, string $activityDate): ?DailySpiritualRecord;

    /** @return array<string, mixed> */
    public function storeOrUpdate(int $memberId, array $data): array;

    public function delete(int $memberId, string $activityDate): bool;
}
