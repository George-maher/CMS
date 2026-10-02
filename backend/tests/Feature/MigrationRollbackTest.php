<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression test for the rollback path of the tenant-hierarchy migrations.
 *
 * `2026_09_13_000002_add_stage_id_to_qr_invites` created both a foreign key
 * and an index (`qr_invites_stage_id_idx`) on `qr_invites.stage_id`, but its
 * down() dropped only the foreign key and the column. SQLite refuses to drop a
 * column that an index still references, so any rollback reaching that
 * migration aborted with:
 *
 *   SQLSTATE[HY000]: General error: 1 error in index qr_invites_stage_id_idx
 *   after drop column: no such column: stage_id
 *
 * PostgreSQL concealed the bug: ALTER TABLE ... DROP COLUMN silently removes
 * dependent indexes, so the rollback looked healthy in development and only
 * failed on the SQLite connection. A driver-blind suite therefore never sees
 * it unless a rollback is actually performed.
 *
 * Uses RefreshDatabase rather than DatabaseMigrations on purpose:
 * DatabaseMigrations tears down by rolling back the ENTIRE history, and the
 * 2025 migrations in this repository have several down() methods that cannot
 * undo their own up() (see scripts/scan-broken-migration-rollbacks.php). That
 * is pre-existing and out of scope here, but it does mean a full teardown is
 * not available as a test fixture. The migrator is therefore driven directly,
 * over the window this pass actually touches.
 */
class MigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The newest migrations, newest first, down to and including
     * 2026_09_13_000002 — the qr_invites stage_id migration whose down() was
     * broken.
     */
    private const ROLLBACK_STEPS = 4;

    public function test_tenant_migrations_roll_back_and_re_apply_cleanly(): void
    {
        // Fully migrated by DatabaseMigrations in setUp().
        $this->assertTrue(
            Schema::hasColumn('qr_invites', 'stage_id'),
            'precondition: the stage_id column exists after migrating.'
        );

        // Roll back far enough to undo 2026_09_13_000002.
        //
        // Artisan::call() rather than $this->artisan(): TestCase sets
        // `mockConsoleOutput = false` (a Windows workaround), which makes
        // $this->artisan() return a plain int instead of a PendingCommand.
        $this->assertSame(0, Artisan::call('migrate:rollback', [
            '--step' => self::ROLLBACK_STEPS,
            '--force' => true,
        ]));

        $this->assertFalse(
            Schema::hasColumn('qr_invites', 'stage_id'),
            'the stage_id column is gone after rolling back past its migration.'
        );
        $this->assertFalse(
            $this->hasIndex('qr_invites', 'qr_invites_stage_id_idx'),
            'the index is gone too — it must not survive its column, which is '
            .'exactly what made SQLite reject the DROP COLUMN.'
        );

        // Re-apply and prove the schema comes back identical.
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $this->assertTrue(Schema::hasColumn('qr_invites', 'stage_id'));
        $this->assertTrue(
            $this->hasIndex('qr_invites', 'qr_invites_stage_id_idx'),
            'up() recreates the index, so migrate -> rollback -> migrate is lossless.'
        );
    }

    public function test_composite_tenant_constraints_can_be_dropped_and_recreated(): void
    {
        $this->assertSame(
            0,
            Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]),
            'rolling back the newest migration must succeed.'
        );

        $this->assertSame(
            0,
            $this->compositeConstraintCount(),
            'rolling back the newest migration removes every composite tenant constraint.'
        );

        $this->assertSame(
            0,
            Artisan::call('migrate', ['--force' => true]),
            're-applying must succeed.'
        );

        $this->assertSame(
            7,
            $this->compositeConstraintCount(),
            're-applying recreates all seven composite tenant constraints.'
        );
    }

    private function compositeConstraintCount(): int
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $total = 0;

            foreach (['classes', 'users', 'events', 'event_targets', 'qr_invites'] as $table) {
                $ddl = DB::selectOne(
                    "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
                    [$table]
                );

                $total += preg_match_all(
                    '/CONSTRAINT\s+\w+_church_(?:stage|class)_fk\s+FOREIGN KEY\s*\(church_id,/i',
                    (string) $ddl?->sql
                );
            }

            return $total;
        }

        return (int) DB::selectOne(
            "SELECT COUNT(*) AS c FROM pg_constraint
             WHERE contype = 'f'
               AND (conname LIKE '%_church_stage_fk' OR conname LIKE '%_church_class_fk')"
        )->c;
    }

    private function hasIndex(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return DB::selectOne(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
                [$table, $index]
            ) !== null;
        }

        return DB::selectOne(
            'SELECT 1 AS present FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            [$table, $index]
        ) !== null;
    }
}
