<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanAuditLogs extends Command
{
    protected $signature = 'app:clean-audit-logs
        {--days=90 : Archive audit logs older than this many days}
        {--dry-run : Show what would be deleted without actually deleting}
        {--force : Skip confirmation prompt}';

    protected $description = 'Archive and remove audit log entries older than the specified retention period';

    public function handle(): int
    {
        $retentionDays = (int) $this->option('days');
        $isDryRun = $this->option('dry-run');
        $cutoffDate = now()->subDays($retentionDays);

        $this->info("Audit Log Cleanup — Retention: {$retentionDays} days");
        $this->newLine();

        $count = DB::table('audit_logs')->where('created_at', '<', $cutoffDate)->count();

        if ($count === 0) {
            $this->info('No audit logs found older than ' . $cutoffDate->toDateString() . '.');
            return self::SUCCESS;
        }

        $this->line("Found {$count} audit log entries older than {$cutoffDate->toDateString()}.");
        $this->newLine();

        if (!$isDryRun && !$this->option('force')) {
            if (!$this->confirm("Are you sure you want to archive {$count} audit log entries?")) {
                $this->info('Operation cancelled.');
                return self::FAILURE;
            }
        }

        if ($isDryRun) {
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Retention Period', "{$retentionDays} days"],
                    ['Cutoff Date', $cutoffDate->toDateString()],
                    ['Records to Archive', number_format($count)],
                    ['Action', 'Dry run — no changes made'],
                ]
            );
            return self::SUCCESS;
        }

        $this->line('Archiving audit logs...');
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $archivedCount = 0;
        $chunkSize = 500;

        DB::table('audit_logs')
            ->where('created_at', '<', $cutoffDate)
            ->orderBy('id')
            ->chunk($chunkSize, function ($logs) use (&$archivedCount, $bar) {
                $timestamp = now()->format('Y-m-d_H-i-s');
                $filename = "audit-archive-{$timestamp}-chunk-" . uniqid() . '.json';

                $data = $logs->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'user_id' => $log->user_id,
                        'action' => $log->action,
                        'resource_type' => $log->resource_type,
                        'resource_id' => $log->resource_id,
                        'old_values' => $log->old_values,
                        'new_values' => $log->new_values,
                        'ip_address' => $log->ip_address,
                        'user_agent' => $log->user_agent,
                        'created_at' => $log->created_at,
                    ];
                })->toArray();

                try {
                    Storage::disk('local')->put(
                        "audit-archives/{$filename}",
                        json_encode($data, JSON_PRETTY_PRINT)
                    );
                } catch (\Exception $e) {
                    $this->error("Failed to write archive file: {$e->getMessage()}");
                    return false;
                }

                $ids = $logs->pluck('id')->toArray();
                DB::table('audit_logs')->whereIn('id', $ids)->delete();

                $archivedCount += count($logs);
                $bar->advance(count($logs));
            });

        $bar->finish();
        $this->newLine(2);

        $storagePath = Storage::disk('local')->path('audit-archives');
        $this->info('Audit log cleanup complete!');
        $this->line("  Records archived: {$archivedCount}");
        $this->line("  Archive location: {$storagePath}");

        return self::SUCCESS;
    }
}
