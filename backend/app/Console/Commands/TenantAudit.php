<?php

namespace App\Console\Commands;

use App\Services\TenantConsistencyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TenantAudit extends Command
{
    protected $signature = 'tenant:audit
        {--repair : Align child church_id to the parent it points at. Modifies data.}
        {--force : Skip the interactive confirmation required by --repair}';

    protected $description = 'Report cross-tenant inconsistencies (church_id disagreeing with the referenced stage/class). Read-only unless --repair is passed.';

    public function handle(TenantConsistencyService $consistency): int
    {
        $repair = (bool) $this->option('repair');

        $this->info('Tenant consistency audit');
        $this->line('Driver: <fg=cyan>'.DB::connection()->getDriverName().'</>  Database: <fg=cyan>'.DB::connection()->getDatabaseName().'</>');
        $this->newLine();

        $report = $consistency->inspect();

        if ($report === []) {
            $this->warn('No tenant relationship tables were found. Has the schema been migrated?');

            return self::FAILURE;
        }

        $rows = [];
        $repairable = 0;
        $orphaned = 0;

        foreach ($report as $relationship) {
            $repairable += $relationship['mismatched'] + $relationship['unowned'];
            $orphaned += $relationship['orphaned'];

            $rows[] = [
                $relationship['child'],
                $relationship['column'],
                $relationship['parent'],
                $relationship['mismatched'],
                $relationship['unowned'],
                $relationship['orphaned'],
            ];
        }

        $this->table(
            ['child table', 'column', 'parent table', 'cross-church', 'no church', 'orphaned'],
            $rows
        );

        // Show the offending ids so an operator can judge the blast radius
        // before repairing. Capped, with the exact total above the table.
        foreach ($report as $relationship) {
            if ($relationship['samples'] === []) {
                continue;
            }

            $total = $relationship['mismatched'] + $relationship['unowned'];

            $this->newLine();
            $this->line(sprintf(
                '<fg=yellow>%s.%s</> disagrees with <fg=yellow>%s</> for %d row(s) — proposed direction: set %s.church_id to the parent church.',
                $relationship['child'],
                $relationship['column'],
                $relationship['parent'],
                $total,
                $relationship['child']
            ));

            $this->table(
                ['child id', $relationship['column'], 'reason', 'current church_id', 'parent church_id', 'direction'],
                array_map(static fn (array $sample): array => [
                    $sample['id'],
                    $sample['column'],
                    $sample['reason'],
                    $sample['church_id'] ?? 'NULL',
                    $sample['parent_church_id'] ?? 'NULL',
                    sprintf('church_id: %s → %s', $sample['church_id'] ?? 'NULL', $sample['parent_church_id'] ?? 'NULL'),
                ], $relationship['samples'])
            );

            if ($total > count($relationship['samples'])) {
                $this->line(sprintf(
                    '  … and %d more (use <info>--repair</info> to fix all of them).',
                    $total - count($relationship['samples'])
                ));
            }
        }

        $this->newLine(2);

        if ($orphaned > 0) {
            $this->warn("{$orphaned} orphaned row(s) point at a stage/class that does not exist.");
            $this->line('  These are NEVER modified by --repair: there is no parent to take a church from,');
            $this->line('  and blanking church_id would destroy the only tenant information left on the row.');
            $this->line('  Resolve them by restoring the missing parent or clearing the dangling reference.');
        }

        if ($repairable === 0 && $orphaned === 0) {
            $this->info('No inconsistencies found. The composite tenant foreign keys can be applied safely.');

            return self::SUCCESS;
        }

        if (! $repair) {
            $this->warn("{$repairable} row(s) would be changed by a repair.");

            if ($repairable > 0) {
                $this->line('  The composite tenant FK migration refuses to run while these exist, because');
                $this->line('  repairing them silently re-homes tenants. Review the table above, then run:');
                $this->newLine();
                $this->line('    <info>php artisan tenant:audit --repair</info>');
            }

            return $repairable > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->warn("Repair mode: {$repairable} row(s) will have church_id aligned to the parent they point at.");
        $this->line('  This is a cross-tenant data mutation. Take a database backup first.');

        if (! $this->option('force') && ! $this->confirm('Proceed with the repair?', true)) {
            $this->info('Cancelled. No data was modified.');

            return self::SUCCESS;
        }

        $repaired = $consistency->repair();

        $this->newLine();
        $this->table(
            ['child table', 'column', 'rows updated'],
            array_map(static fn (array $entry): array => [
                $entry['child'],
                $entry['column'],
                $entry['updated'],
            ], $repaired)
        );

        $remaining = $consistency->countRepairable();

        if ($remaining === 0) {
            $this->info('Repair complete. The composite tenant foreign keys can now be applied.');

            return self::SUCCESS;
        }

        $this->error("{$remaining} row(s) could not be repaired. Re-run the audit and escalate.");

        return self::FAILURE;
    }
}
