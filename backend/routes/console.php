<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:reset-data', function () {
    $this->call(\App\Console\Commands\ResetApplicationData::class);
})->purpose('Delete ALL application data, preserve schema, create fresh Platform Admin');

// ──────────────────────────────────────────────
// Scheduled Tasks
// ──────────────────────────────────────────────

Schedule::command('app:clean-expired-invites --days=7')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler-cleanup.log'));

Schedule::command('app:clean-audit-logs --days=90 --force')
    ->weeklyOn(0, '04:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler-audit.log'));
