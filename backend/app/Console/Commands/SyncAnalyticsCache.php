<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncAnalyticsCache extends Command
{
    protected $signature = 'analytics:cache';

    protected $description = 'Pre-compute and cache analytics data for dashboards';

    public function handle(): int
    {
        $this->warn('This command requires a cache driver that supports tags (Redis/Memcached).');

        return self::SUCCESS;
    }
}
