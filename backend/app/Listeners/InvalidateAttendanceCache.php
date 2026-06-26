<?php

namespace App\Listeners;

use App\Events\AttendanceRecorded;
use App\Services\CacheService;

class InvalidateAttendanceCache
{
    public function __construct(
        private readonly CacheService $cacheService,
    ) {}

    public function handle(AttendanceRecorded $event): void
    {
        $this->cacheService->invalidateAttendance($event->churchId);
        $this->cacheService->invalidateDashboard($event->churchId);
    }
}
