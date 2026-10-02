<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use App\Services\TenantConsistencyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The cross-tenant repair step of the composite-FK migration.
 *
 * Two properties are proven here, and both came out of a real defect:
 *
 *  1. The migration used raw SQL containing `church_id IS NOT (SELECT ...)`.
 *     That is valid SQLite and a SYNTAX ERROR on PostgreSQL, so `migrate`
 *     aborted on the production driver and the composite constraints were
 *     never created. SQLite-only testing could not have caught it.
 *
 *  2. That same SQL set `church_id = NULL` for any child pointing at a
 *     missing parent, because the subquery returned NULL. It destroyed the
 *     only tenant information such a row had.
 *
 * The tests below pin both: the inspector is portable (it runs on whichever
 * driver the suite is on, and is exercised on PostgreSQL too), and the repair
 * never blanks a `church_id`.
 */
class TenantConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Church $churchA;

    private Church $churchB;

    private Stage $stageA;

    private Classe $classA;

    private Classe $classB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->churchA = Church::factory()->create();
        $this->churchB = Church::factory()->create();

        $this->stageA = Stage::factory()->forChurch($this->churchA)->create();

        $this->classA = Classe::factory()->forChurch($this->churchA)
            ->state(['stage_id' => $this->stageA->id])->create();
        $this->classB = Classe::factory()->forChurch($this->churchB)
            ->state(['stage_id' => Stage::factory()->forChurch($this->churchB)->create()->id])->create();
    }

    /**
     * A database whose data was written by correct application code must
     * report nothing, so a clean deploy is never blocked.
     */
    public function test_clean_database_reports_no_inconsistencies(): void
    {
        User::factory()->create([
            'church_id' => $this->churchA->id,
            'class_id' => $this->classA->id,
            'stage_id' => $this->stageA->id,
        ]);

        $consistency = app(TenantConsistencyService::class);

        $this->assertSame(0, $consistency->countRepairable());
        $this->assertSame([], $this->offenders($consistency));
    }

    /**
     * MATCH SIMPLE lets `(church_id = NULL, class_id = X)` through, so this
     * state is reachable without disabling any constraint. The inspector must
     * see it and the repair must fill in the church from the parent.
     */
    public function test_user_without_a_church_is_detected_and_repaired_from_its_class(): void
    {
        $user = User::factory()->create([
            'church_id' => null,
            'class_id' => $this->classA->id,
        ]);

        $consistency = app(TenantConsistencyService::class);

        $usersClass = $this->relationship($consistency, 'users', 'class_id');
        $this->assertSame(1, $usersClass['unowned'], 'a user with a class but no church is unowned.');

        $consistency->repair();

        $this->assertSame(
            $this->churchA->id,
            (int) $user->fresh()->church_id,
            'the repair takes the church from the class the user points at.'
        );
        $this->assertSame(0, $consistency->countRepairable());
    }

    /**
     * The migration must not silently repair. It refuses and names the command.
     */
    public function test_migration_refuses_to_repair_implicitly(): void
    {
        $migration = require database_path('migrations/2026_09_29_000001_add_composite_tenant_foreign_keys.php');

        $method = new \ReflectionMethod($migration, 'guardAgainstUnreviewedCrossTenantRows');
        $method->setAccessible(true);

        // Clean database: the guard is a no-op.
        $method->invoke($migration);
        $this->addToAssertionCount(1);

        // Now introduce a row the repair would change.
        User::factory()->create([
            'church_id' => null,
            'class_id' => $this->classA->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tenant:audit/');

        $method->invoke($migration);
    }

    /**
     * The repair direction must be reported, not just the count, so an operator
     * can judge the blast radius before agreeing to it.
     */
    public function test_inspector_reports_ids_and_the_proposed_direction(): void
    {
        User::factory()->create([
            'church_id' => null,
            'class_id' => $this->classA->id,
        ]);

        $relationship = $this->relationship(app(TenantConsistencyService::class), 'users', 'class_id');

        $this->assertCount(1, $relationship['samples']);
        $this->assertNull($relationship['samples'][0]['church_id']);

        // parent_church_id is the CHURCH of the referenced class, so it must be
        // compared with churchA->id. The original assertion compared it with
        // classA->id and passed only because SQLite draws every rowid from one
        // database-wide counter, making classes.id and churches.id collide.
        // PostgreSQL uses a per-table sequence, so the two differ and the
        // assertion failed there. It was never testing what it appeared to.
        $this->assertSame(
            (string) $this->classA->id,
            (string) $relationship['samples'][0]['column'],
            'the sample must identify the class the user points at.'
        );
        $this->assertSame(
            (string) $this->churchA->id,
            (string) $relationship['samples'][0]['parent_church_id'],
            'the report must name the church the row would move to.'
        );
    }

    /**
     * Guard against the specific syntax that broke PostgreSQL.
     *
     * `IS NOT (subquery)` parses on SQLite and throws on PostgreSQL, so the
     * whole class of bug is invisible to a SQLite-only suite. This test fails
     * on SQLite the moment anyone reintroduces it, by asserting the file no
     * longer contains that construct.
     */
    public function test_migration_contains_no_driver_specific_is_not_subquery(): void
    {
        $source = $this->executableSource(
            database_path('migrations/2026_09_29_000001_add_composite_tenant_foreign_keys.php')
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\bIS\s+NOT\s*\(\s*SELECT/i',
            $source,
            '`IS NOT (subquery)` is valid SQLite but a syntax error on PostgreSQL. '
            .'It previously aborted the migration on the production driver, so the '
            .'composite tenant foreign keys were never actually created.'
        );
    }

    /**
     * The migration file with every comment removed.
     *
     * Comments are stripped because the file documents the removed SQL
     * verbatim, and that documentation must not itself trip the guard.
     */
    private function executableSource(string $path): string
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $code = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * The composite constraints must still be present after all of the above.
     */
    public function test_composite_constraints_survive_the_audit_cycle(): void
    {
        $this->assertTrue(Schema::hasIndex('stages', 'stages_church_id_id_unique'));
        $this->assertTrue(Schema::hasIndex('classes', 'classes_church_id_id_unique'));

        // And the invariant still holds after repair ran.
        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'church_id' => $this->churchA->id,
            'class_id' => $this->classB->id,
            'name' => 'Cross Tenant',
            'email' => 'cross-tenant-audit@test.com',
            'password' => 'x',
            'role' => 'member',
            'application_status' => 'approved',
        ]);
    }

    // =================================================================
    // helpers
    // =================================================================

    /**
     * @return array<int, mixed>
     */
    private function offenders(TenantConsistencyService $consistency): array
    {
        return array_values(array_filter(
            $consistency->inspect(),
            static fn (array $r): bool => $r['mismatched'] > 0 || $r['unowned'] > 0
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function relationship(
        TenantConsistencyService $consistency,
        string $child,
        string $column
    ): array {
        foreach ($consistency->inspect() as $relationship) {
            if ($relationship['child'] === $child && $relationship['column'] === $column) {
                return $relationship;
            }
        }

        $this->fail("No inspector entry for {$child}.{$column}.");
    }
}
