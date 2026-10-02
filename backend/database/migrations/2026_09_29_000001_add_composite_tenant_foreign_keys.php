<?php

use App\Services\TenantConsistencyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Makes cross-tenant rows unrepresentable at the database layer.
 *
 * Application code already refuses to persist `church_id = A` together with a
 * resource owned by `church_id = B`, and the HTTP regression tests prove it.
 * But "the application checks" is a convention, not a guarantee: a raw insert,
 * a future code path, a queued job, or a service bug can all still create an
 * inconsistent pair. This migration moves that invariant into PostgreSQL.
 *
 * A composite foreign key is required because a single-column FK can only prove
 * that an id exists *somewhere*. `(church_id, stage_id) -> stages(church_id, id)`
 * proves the stage belongs to that specific church.
 *
 * Two SQL semantics are relied upon deliberately:
 *
 *  - MATCH SIMPLE (the default): the constraint is not evaluated when ANY
 *    referencing column is NULL. Every column here is nullable, so a member with
 *    no class is unaffected. This is why `users.class_id = NULL` still works.
 *  - The parent key must be covered by a UNIQUE constraint, hence the
 *    supporting `UNIQUE(church_id, id)` indexes added below.
 *
 * Driver behaviour:
 *  - PostgreSQL adds the constraints with ALTER TABLE.
 *  - SQLite cannot add a foreign key to an existing table, so on that driver the
 *    affected table is rebuilt. The rebuild is a no-op in production (PostgreSQL)
 *    and only runs for the test connection, which recreates the schema each run.
 */
return new class extends Migration
{
    /**
     * Child table => [parent table, child column, constraint name].
     *
     * Every entry constrains `(child.church_id, child.<column>)` to
     * `(parent.church_id, parent.id)`.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const CONSTRAINTS = [
        ['classes', 'stage_id', 'stages', 'classes_church_stage_fk'],
        ['users', 'stage_id', 'stages', 'users_church_stage_fk'],
        ['users', 'class_id', 'classes', 'users_church_class_fk'],
        ['events', 'class_year_id', 'classes', 'events_church_class_fk'],
        ['event_targets', 'class_id', 'classes', 'event_targets_church_class_fk'],
        ['qr_invites', 'stage_id', 'stages', 'qr_invites_church_stage_fk'],
        ['qr_invites', 'class_id', 'classes', 'qr_invites_church_class_fk'],
    ];

    public function up(): void
    {
        $orphaned = $this->guardAgainstUnreviewedCrossTenantRows();

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        // On SQLite the parent unique keys are created *after* the rebuilds,
        // because rebuilding a table drops its indexes. SQLite validates a
        // foreign key at DML time rather than DDL time, so the constraint can be
        // declared first and still be enforced once the parent key appears.
        if (! $isSqlite) {
            $this->ensureParentUniqueKeys();
        }

        if ($isSqlite) {
            // A SQLite rebuild strips and re-adds every composite tenant
            // constraint on the table, so each table must be rebuilt exactly
            // once with the complete set. `users` carries two of them.
            foreach ($this->constraintsByTable() as $child => $definitions) {
                $needed = array_values(array_filter(
                    $definitions,
                    fn (array $d): bool => ! $this->foreignKeyExists($child, $d[2])
                ));

                if ($needed === []) {
                    continue;
                }

                $this->rebuildSqliteTable($child, $this->constraintSql($needed));
            }

            $this->ensureParentUniqueKeys();

            return;
        }

        foreach (self::CONSTRAINTS as [$child, $childColumn, $parent, $name]) {
            if ($this->foreignKeyExists($child, $name)) {
                continue;
            }

            // NOT VALID, and only when it is actually required.
            //
            // PostgreSQL VALIDATES EXISTING ROWS when a constraint is added.
            // An orphaned row — a child pointing at a stage/class that no
            // longer exists — can never satisfy the composite key, so a plain
            // ADD CONSTRAINT aborts the whole deploy. That was found by the
            // production rehearsal, not by the test suite, because no test
            // database contains an orphan at migration time.
            //
            // When orphans exist we therefore add the constraint NOT VALID:
            // PostgreSQL enforces it on every future INSERT and UPDATE
            // immediately, while leaving the pre-existing bad rows untouched.
            // That is the only option that avoids both unacceptable
            // alternatives — deleting the orphan's data, or refusing to deploy.
            //
            // On a healthy database ($orphaned === 0) the constraint is added
            // fully VALIDATED, so the post-migration check reports a clean
            // state rather than seven warnings.
            //
            // Once orphans are resolved, promote with:
            //   ALTER TABLE <child> VALIDATE CONSTRAINT <name>;
            // `tenant:verify-schema` prints that exact statement per
            // constraint that is still NOT VALID.
            //
            // SQLite needs no equivalent: it validates a foreign key at DML
            // time rather than DDL time, so the rebuild below already leaves
            // legacy orphans alone while enforcing the constraint on new writes.
            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (church_id, %s) REFERENCES %s (church_id, id)%s',
                $child,
                $name,
                $childColumn,
                $parent,
                $orphaned > 0 ? ' NOT VALID' : '',
            ));
        }
    }

    public function down(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($isSqlite) {
            // One rebuild per table, with no constraints re-added.
            foreach (array_reverse(array_keys($this->constraintsByTable())) as $child) {
                $this->rebuildSqliteTable($child, '');
            }

            return;
        }

        foreach (array_reverse(self::CONSTRAINTS) as [$child, , , $name]) {
            if (! $this->foreignKeyExists($child, $name)) {
                continue;
            }

            Schema::table($child, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropForeign($name);
            });
        }
    }

    /**
     * Refuse to proceed while legacy cross-tenant rows exist.
     *
     * History — this step used to repair them automatically, with raw SQL:
     *
     *   UPDATE {$child} SET church_id = (SELECT p.church_id FROM {$parent} p WHERE p.id = {$child}.{$col})
     *    WHERE {$col} IS NOT NULL AND church_id IS NOT (SELECT ...)
     *
     * That was wrong in three independent ways, and the first one meant the
     * migration had never once succeeded on PostgreSQL:
     *
     *  1. `IS NOT (subquery)` is valid SQLite but a SYNTAX ERROR in
     *     PostgreSQL. Running `migrate` on the production driver aborted here,
     *     so none of the constraints below were ever created. SQLite-only
     *     testing hid it completely.
     *  2. For a child pointing at a missing parent, the subquery returns NULL
     *     and the row's `church_id` was silently set to NULL — destroying the
     *     only tenant information the row had.
     *  3. Even when correct, aligning a child to its parent re-homes real data
     *     across churches (a class moving to its stage's church drags every
     *     user assigned to it). That is a business decision, not a migration
     *     detail, and it must not happen implicitly during a deploy.
     *
     * So the migration no longer mutates anything. It fails loudly and points
     * at `php artisan tenant:audit`, which reports every offending row with
     * its id and the proposed direction, and only modifies rows when an
     * operator passes --repair and confirms.
     *
     * @return int Number of orphaned rows found, which decides whether the
     *             constraints can be added VALIDATED or must be NOT VALID.
     *
     * @throws RuntimeException
     */
    private function guardAgainstUnreviewedCrossTenantRows(): int
    {
        $consistency = new TenantConsistencyService;

        $report = $consistency->inspect();

        $offenders = [];
        $orphaned = 0;

        foreach ($report as $relationship) {
            $orphaned += $relationship['orphaned'];

            if ($relationship['mismatched'] > 0 || $relationship['unowned'] > 0) {
                $offenders[] = sprintf(
                    '  %s.%s (parent %s): %d cross-church, %d without a church',
                    $relationship['child'],
                    $relationship['column'],
                    $relationship['parent'],
                    $relationship['mismatched'],
                    $relationship['unowned']
                );
            }
        }

        if ($offenders === []) {
            if ($orphaned > 0) {
                // Orphans are left alone on purpose. The constraints are added
                // NOT VALID so they still apply to every future write, which
                // means pre-existing orphans do not have to block a deploy —
                // and must not be "fixed" by deleting or blanking the row,
                // which is the only tenant information such a row still has.
                Log::warning('[tenant] orphaned rows found before applying composite foreign keys', [
                    'orphaned_rows' => $orphaned,
                    'action' => 'constraints added NOT VALID; orphans left untouched and reported by tenant:audit',
                ]);
            }

            return $orphaned;
        }

        throw new RuntimeException(
            "Refusing to add composite tenant foreign keys: cross-tenant rows already exist.\n"
            .implode("\n", $offenders)."\n\n"
            ."Repairing them re-homes real data across churches, so it is not done implicitly.\n"
            ."Review the affected rows, then run:\n\n"
            ."    php artisan tenant:audit\n"
            ."    php artisan tenant:audit --repair\n\n"
            .'Take a database backup before repairing.'
        );
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private function constraintsByTable(): array
    {
        $grouped = [];

        foreach (self::CONSTRAINTS as [$child, $childColumn, $parent, $name]) {
            $grouped[$child][] = [$childColumn, $parent, $name];
        }

        return $grouped;
    }

    /**
     * `UNIQUE(church_id, id)` on the parent tables.
     *
     * Logically redundant because `id` is the primary key, but required before
     * a composite foreign key may reference `(church_id, id)`.
     */
    private function ensureParentUniqueKeys(): void
    {
        $indexes = [
            ['stages', 'stages_church_id_id_unique'],
            ['classes', 'classes_church_id_id_unique'],
        ];

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        foreach ($indexes as [$table, $index]) {
            if (Schema::hasIndex($table, $index)) {
                continue;
            }

            if ($isSqlite) {
                DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$index} ON {$table} (church_id, id)");

                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->unique(['church_id', 'id'], $index);
            });
        }
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite does not expose constraint names through
            // PRAGMA foreign_key_list, so the stored DDL is the only source.
            $ddl = DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );

            if ($ddl === null || $ddl->sql === null) {
                return false;
            }

            return (bool) preg_match(
                '/CONSTRAINT\s+'.preg_quote($name, '/').'\s+FOREIGN KEY/i',
                (string) $ddl->sql
            );
        }

        return DB::selectOne(
            'SELECT 1 AS present FROM information_schema.table_constraints
             WHERE constraint_type = ? AND constraint_name = ? AND table_name = ?',
            ['FOREIGN KEY', $name, $table]
        ) !== null;
    }

    /**
     * Renders the SQL fragment for a set of composite tenant constraints.
     *
     * @param  array<int, array{0: string, 1: string, 2: string}>  $definitions
     */
    private function constraintSql(array $definitions): string
    {
        $sql = '';

        foreach ($definitions as [$childColumn, $parent, $name]) {
            // Deliberately NO ON UPDATE CASCADE. With a cascade, changing a
            // stage's church_id silently re-homes every class that belongs to
            // it — an implicit cross-tenant mutation of exactly the kind this
            // constraint exists to prevent. Moving a resource between
            // churches must be an explicit, audited operation, so the default
            // NO ACTION makes the database refuse it instead.
            $sql .= ",\n    CONSTRAINT {$name} FOREIGN KEY (church_id, {$childColumn})"
                ." REFERENCES {$parent} (church_id, id)";
        }

        return $sql;
    }

    /**
     * Removes every composite tenant constraint from a CREATE statement.
     *
     * Called before appending, so a rebuild is idempotent and never duplicates
     * a constraint.
     */
    private function stripCompositeConstraints(string $create): string
    {
        return preg_replace(
            '/,\s*CONSTRAINT\s+\w+\s+FOREIGN KEY\s*\(church_id,\s*\w+\)\s*REFERENCES\s+\w+\s*\(church_id,\s*id\)\s*(?:ON UPDATE CASCADE)?/i',
            '',
            $create
        ) ?? $create;
    }

    /**
     * Rebuilds a SQLite table in place.
     *
     * SQLite cannot add a foreign key to an existing table, so the table is
     * recreated from its own DDL with the composite tenant constraint appended.
     * Three things must be preserved and each was a real bug when omitted:
     *
     *  - the table DDL itself, so implicit UNIQUE constraints (for example
     *    classes.church_id + stage_id + name) survive;
     *  - explicitly created indexes, which live in sqlite_master and are
     *    destroyed by the DROP;
     *  - the data, copied through a temporary holding table.
     *
     * Only reachable on SQLite (the test connection); PostgreSQL uses
     * ALTER TABLE ADD CONSTRAINT and needs none of this.
     */
    private function rebuildSqliteTable(string $table, string $addConstraintsSql): void
    {
        $existing = DB::selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );

        if ($existing === null || $existing->sql === null) {
            return;
        }

        $ddl = (string) $existing->sql;
        $create = $this->stripCompositeConstraints($ddl);

        if ($addConstraintsSql !== '') {
            $create = preg_replace('/\)\s*$/', $addConstraintsSql."\n)", $create, 1) ?? $create;
        }

        // Explicit indexes have a stored CREATE statement; implicit indexes
        // (sql IS NULL) belong to the table DDL and are kept with it.
        $explicitIndexes = DB::select(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL",
            [$table]
        );

        // Column order is taken from the DDL, not from the data, so an empty
        // table still round-trips correctly.
        $columns = $this->ddlColumnNames($ddl);

        DB::statement('PRAGMA foreign_keys = OFF');

        $holding = '__fk_rebuild';
        DB::statement('DROP TABLE IF EXISTS '.$holding);

        if ($columns !== []) {
            $columnList = implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', $columns));
            DB::statement('CREATE TABLE '.$holding.' AS SELECT '.$columnList.' FROM '.$table);
        }

        DB::statement('DROP TABLE '.$table);
        DB::statement($create);

        if ($columns !== []) {
            $quoted = implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', $columns));
            DB::statement('INSERT INTO '.$table.' ('.$quoted.') SELECT '.$quoted.' FROM '.$holding);
            DB::statement('DROP TABLE '.$holding);
        }

        foreach ($explicitIndexes as $index) {
            DB::statement((string) $index->sql);
        }

        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Extracts the ordered column names from a SQLite CREATE TABLE statement.
     *
     * Table-level constraint clauses (FOREIGN KEY / UNIQUE / CHECK / PRIMARY
     * KEY) are skipped: their contents are not column definitions.
     *
     * @return array<int, string>
     */
    private function ddlColumnNames(string $ddl): array
    {
        if (preg_match('/^\s*create\s+table[^(]*\((.*)\)\s*(?:without|with)\s+rowid\s*$/is', $ddl, $m) !== 1
            && preg_match('/^\s*create\s+table[^(]*\((.*)\)\s*;?\s*$/is', $ddl, $m) !== 1) {
            return [];
        }

        $body = $m[1];
        $columns = [];

        // Split on top-level commas only, so a DECIMAL(10,2) or a compound
        // FOREIGN KEY (a, b) is not torn apart.
        $depth = 0;
        $buffer = '';
        $parts = [];

        foreach (str_split($body) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = $buffer;
        }

        foreach ($parts as $part) {
            $part = trim($part);

            if (preg_match('/^(constraint|foreign\s+key|unique|check|primary\s+key)\b/i', $part) === 1) {
                continue;
            }

            if (preg_match('/^["`]?([A-Za-z_]\w*)["`]?\s+\S/', $part, $c) === 1) {
                $columns[] = $c[1];
            }
        }

        return $columns;
    }
};
