<?php

namespace App\Console\Commands;

use App\Models\QRInvite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanExpiredInvites extends Command
{
    protected $signature = 'app:clean-expired-invites
        {--days=7 : Soft-delete invites expired more than this many days ago}
        {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Clean up expired and revoked QR invites';

    public function handle(): int
    {
        $retentionDays = (int) $this->option('days');
        $isDryRun = $this->option('dry-run');
        $cutoffDate = now()->subDays($retentionDays);

        $this->info("Expired Invite Cleanup — Retention: {$retentionDays} days");

        $query = QRInvite::withoutGlobalScope(\App\Models\Scopes\ChurchScope::class)
            ->where(function ($q) use ($cutoffDate) {
                $q->where('expires_at', '<', $cutoffDate)
                    ->orWhere('is_revoked', true);
            });

        if (!$isDryRun) {
            $count = $query->count();
            $query->delete();

            Log::info('Expired invites cleaned', [
                'count' => $count,
                'retention_days' => $retentionDays,
            ]);

            $this->info("Deleted {$count} expired/revoked invites.");
        } else {
            $count = $query->count();
            $this->info("Found {$count} invites that would be deleted (dry run).");
        }

        return self::SUCCESS;
    }
}
