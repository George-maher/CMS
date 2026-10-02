<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Stage;
use App\Models\User;
use App\Services\TenantConsistencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The destructive edge case of the cross-tenant repair, isolated because it
 * needs the foreign-key guard lifted.
 *
 * A row whose child column points at a parent that does not exist has no
 * church to inherit. The subquery-based repair that this replaced evaluated to
 * NULL for such a row and wrote that NULL into `church_id`, destroying the
 * only tenant information the row still carried.
 *
 * This uses RefreshDatabase, like the rest of the suite. Creating a dangling
 * reference normally needs the foreign-key guard lifted, and on SQLite that
 * has to be done with `PRAGMA defer_foreign_keys` rather than
 * `PRAGMA foreign_keys = OFF` — the latter is silently ignored while a
 * transaction is open, and RefreshDatabase keeps every test inside one.
 * Deferring is enough here because the enclosing transaction is rolled back
 * rather than committed, so the deferred check never actually fires.
 */
class TenantOrphanRepairTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The repair must leave an orphan completely untouched and only report it.
     */
    public function test_orphan_is_reported_but_never_given_a_null_church(): void
    {
        $church = Church::factory()->create();
        $stage = Stage::factory()->forChurch($church)->create();

        $userId = $this->insertUserPointingAtMissingClass((int) $church->id);

        $consistency = app(TenantConsistencyService::class);

        $relationship = null;
        foreach ($consistency->inspect() as $entry) {
            if ($entry['child'] === 'users' && $entry['column'] === 'class_id') {
                $relationship = $entry;
            }
        }

        $this->assertNotNull($relationship, 'users.class_id must be inspected.');
        $this->assertSame(1, $relationship['orphaned'], 'a dangling class_id is an orphan.');

        // Orphans are excluded from the repairable count on purpose. If they
        // were included, the migration would refuse to run forever on data it
        // is incapable of fixing.
        $this->assertSame(0, $consistency->countRepairable());

        $consistency->repair();

        $fresh = User::withTrashed()->find($userId);

        $this->assertNotNull($fresh);
        $this->assertSame(
            (int) $church->id,
            (int) $fresh->church_id,
            'An orphan must keep its church_id. The removed repair would have set it '
            .'to NULL here, permanently losing the tenant assignment.'
        );
        $this->assertSame(987654, (int) $fresh->class_id, 'The dangling reference is left for an operator to resolve.');
    }

    /**
     * Sanity check that the fixture really is an orphan and not merely
     * unowned, so the assertion above is testing what it claims to test.
     */
    public function test_fixture_is_a_true_orphan_and_not_merely_unowned(): void
    {
        $church = Church::factory()->create();

        $this->insertUserPointingAtMissingClass((int) $church->id);

        $this->assertSame(
            0,
            DB::table('classes')->where('id', 987654)->count(),
            'the referenced class genuinely does not exist'
        );
    }

    /**
     * Insert a user pointing at a class id that does not exist.
     *
     * Every model-level and single-column foreign key would normally prevent
     * this, so enforcement is lifted for the insert only. This is exactly how
     * such a row arises in a real database: the parent was removed by an older
     * migration, a manual operation, or a partially-restored backup.
     */
    private function insertUserPointingAtMissingClass(int $churchId): int
    {
        $driver = DB::connection()->getDriverName();
        $dropped = [];

        if ($driver === 'sqlite') {
            // SQLite ignores `PRAGMA foreign_keys` inside a transaction, but
            // defer_foreign_keys postpones enforcement to COMMIT — and this
            // transaction is rolled back, never committed.
            DB::statement('PRAGMA defer_foreign_keys = ON');
        } else {
            foreach (['users_class_id_foreign', 'users_church_class_fk'] as $constraint) {
                $exists = DB::selectOne(
                    'SELECT 1 AS present FROM information_schema.table_constraints
                     WHERE constraint_name = ? AND table_name = ?',
                    [$constraint, 'users']
                );

                if ($exists !== null) {
                    DB::statement("ALTER TABLE users DROP CONSTRAINT {$constraint}");
                    $dropped[] = $constraint;
                }
            }
        }

        try {
            DB::table('users')->insert([
                'church_id' => $churchId,
                'class_id' => 987654,
                'name' => 'Orphan',
                'email' => 'orphan-audit@test.com',
                'password' => 'x',
                'role' => 'member',
                'application_status' => 'approved',
            ]);
        } finally {
            if ($driver === 'sqlite') {
                DB::statement('PRAGMA defer_foreign_keys = OFF');
            } else {
                foreach ($dropped as $constraint) {
                    // NOT VALID is essential here.
                    //
                    // Re-adding a validated constraint makes PostgreSQL check
                    // every EXISTING row immediately, and the orphan we just
                    // inserted is exactly such a row — so the restore failed
                    // with a foreign key violation. NOT VALID skips the
                    // backfill check while still enforcing the constraint on
                    // all future writes, which is precisely the semantics the
                    // test needs: the pre-existing bad row stays, nothing new
                    // can be added.
                    DB::statement(str_contains($constraint, 'church_class')
                        ? 'ALTER TABLE users ADD CONSTRAINT users_church_class_fk
                           FOREIGN KEY (church_id, class_id) REFERENCES classes (church_id, id) NOT VALID'
                        : 'ALTER TABLE users ADD CONSTRAINT users_class_id_foreign
                           FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE SET NULL NOT VALID');
                }
            }
        }

        return (int) DB::table('users')->where('email', 'orphan-audit@test.com')->value('id');
    }
}
