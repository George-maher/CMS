<?php

namespace App\Console\Commands;

use App\Services\TenantConsistencyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifies that the database actually ENFORCES tenant isolation.
 *
 * This is the post-migration release gate. It answers a different question
 * from `tenant:audit`:
 *
 *   tenant:audit        → "is the DATA consistent?"
 *   tenant:verify-schema → "are the CONSTRAINTS present and correct?"
 *
 * It exists because a migration can report DONE while having silently failed
 * to create anything. That is not hypothetical: the composite-FK migration
 * aborted on PostgreSQL with a syntax error for its entire life, reported
 * FAILURE only at the point of running, and every SQLite test stayed green
 * because SQLite had silently accepted the surrounding SQL. A migration exit
 * code is not evidence that a constraint exists.
 *
 * Exit code 0 = every invariant holds. Non-zero = do not ship.
 */
class TenantVerifySchema extends Command
{
    protected $signature = 'tenant:verify-schema';

    protected $description = 'Assert that the composite tenant foreign keys and their supporting keys exist and are correctly configured. Exits non-zero on any violation.';

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();

        $this->info('Tenant schema verification');
        $this->line("Driver: <fg=cyan>{$driver}</>  Database: <fg=cyan>".DB::connection()->getDatabaseName().'</>');
        $this->newLine();

        $failures = [];

        // ---------------------------------------------------------
        // 1. Supporting unique keys on the parent tables.
        // ---------------------------------------------------------
        $this->section('Supporting unique keys (required for a composite FK to exist at all)');

        foreach ([['stages', 'stages_church_id_id_unique'], ['classes', 'classes_church_id_id_unique']] as [$table, $index]) {
            $ok = $this->hasIndex($table, $index);
            $this->line("  {$table}.{$index}  ".($ok ? '<fg=green>OK</>' : '<fg=red>MISSING</>'));

            if (! $ok) {
                $failures[] = "{$table} is missing UNIQUE({$table}.church_id, id); every composite foreign key on it would silently match nothing.";
            }
        }

        // ---------------------------------------------------------
        // 2. Composite tenant foreign keys.
        // ---------------------------------------------------------
        $this->newLine();
        $this->section('Composite tenant foreign keys');

        $notValid = [];

        foreach (TenantConsistencyService::RELATIONSHIPS as $relationship) {
            $name = $relationship['constraint'];
            $expected = 'FOREIGN KEY (church_id, '.$relationship['column'].') '
                .'REFERENCES '.$relationship['parent'].' (church_id, id)';

            $state = $this->describeConstraint($relationship['child'], $name);

            if ($state['exists'] === false) {
                $this->line("  {$name}  <fg=red>MISSING</>  ({$relationship['child']} -> {$relationship['parent']})");
                $failures[] = "{$name} is absent: {$relationship['child']}.{$relationship['column']} can reference any church's {$relationship['parent']}.";

                continue;
            }

            $problems = [];

            if (! $state['matches']) {
                $problems[] = 'column mapping is not (church_id, '.$relationship['column'].') -> ('.$relationship['parent'].' church_id, id)';
            }

            if ($state['onUpdateCascade']) {
                // This is the defect the first draft of the migration had: with
                // a cascade, moving a stage between churches silently re-homes
                // every class that belongs to it.
                $problems[] = 'uses ON UPDATE CASCADE, which would silently re-home children across a tenant boundary';
            }

            if ($problems === []) {
                $suffix = $state['notValid']
                    ? '  <fg=yellow>NOT VALID — legacy rows exist; promote once resolved</>'
                    : '';
                $this->line("  {$name}  <fg=green>OK</>  ({$relationship['child']}.{$relationship['column']} -> {$relationship['parent']}){$suffix}");

                if ($state['notValid']) {
                    $notValid[] = "ALTER TABLE {$relationship['child']} VALIDATE CONSTRAINT {$name};";
                }
            } else {
                $this->line("  {$name}  <fg=red>INVALID</>");
                foreach ($problems as $problem) {
                    $this->line('      - '.$problem);
                    $failures[] = "{$name}: {$problem}";
                }
            }
        }

        // ---------------------------------------------------------
        // 3. Behavioural proof, not just catalogue inspection.
        // ---------------------------------------------------------
        $this->newLine();
        $this->section('Behavioural proof (actual write attempt against a throwaway transaction)');

        $behavioural = $this->proveEnforcement();
        foreach ($behavioural as $line => $result) {
            $this->line("  {$line}  ".($result['ok'] ? '<fg=green>OK</>' : '<fg=red>FAIL</>'));

            if (! $result['ok']) {
                $failures[] = "Behavioural check failed: {$line} — {$result['detail']}";
            }
        }

        $this->newLine(2);

        if ($notValid !== []) {
            $this->warn('Some constraints are NOT VALID because legacy rows predate them.');
            $this->line('They ARE enforced for every new INSERT and UPDATE, so no new cross-tenant');
            $this->line('row can be written. Once the legacy rows are resolved, promote them with:');
            $this->newLine();

            foreach ($notValid as $statement) {
                $this->line('    <info>'.$statement.'</info>');
            }

            $this->newLine();
            $this->line('See <info>php artisan tenant:audit</info> for the offending rows.');
            $this->newLine(2);
        }

        if ($failures !== []) {
            $this->error(count($failures).' schema violation(s). Cross-tenant integrity is NOT enforced.');
            $this->newLine();

            foreach ($failures as $failure) {
                $this->line('  • '.$failure);
            }

            $this->newLine();
            $this->line('See <info>docs/TENANT_RULES.md</info> section 6.');

            return self::FAILURE;
        }

        $this->info('All tenant isolation invariants are enforced by the database.');

        return self::SUCCESS;
    }

    /**
     * Actually attempt the writes a cross-tenant attacker would attempt.
     *
     * A constraint existing in the catalogue proves nothing on its own — it
     * must be attached to the right columns, on the right table, with the
     * right match semantics. This inserts and rolls back.
     *
     * @return array<string, array{ok: bool, detail: string}>
     */
    private function proveEnforcement(): array
    {
        $results = [];

        $rows = DB::select('SELECT (SELECT max(id) FROM churches) AS c, (SELECT max(id) FROM stages) AS s, (SELECT max(id) FROM classes) AS k');
        $row = is_object($rows[0] ?? null) ? $rows[0] : null;

        $churchA = $this->intProp($row, 'c');
        $churchB = $churchA >= 2 ? $churchA - 1 : 0;
        $stageA = $this->intProp($row, 's');
        $stageB = $stageA >= 2 ? $stageA - 1 : 0;
        $classA = $this->intProp($row, 'k');
        $classB = $classA >= 2 ? $classA - 1 : 0;

        // Only meaningful when there is enough data to form a cross-church pair.
        if ($churchA < 2 || $stageA < 2 || $classA < 2) {
            $this->line('  <fg=yellow>SKIPPED — fewer than two churches/stages/classes exist; the behavioural proof needs a cross-church pair.</>');

            return $results;
        }

        DB::beginTransaction();

        try {
            // Must be REJECTED: class in church A pointing at church B's stage.
            $results['cross-church class -> foreign stage'] = $this->expectRejected(
                function () use ($churchA, $stageB): void {
                    DB::table('classes')->insert([
                        'church_id' => $churchA,
                        'stage_id' => $stageB,
                        'name' => 'verify-'.$stageB.'-'.uniqid(),
                        'display_order' => 0,
                    ]);
                },
                'the insert was accepted — a class can be attached to another church\'s stage'
            );

            // Must be REJECTED: user in church A assigned church B's class.
            $results['cross-church user -> foreign class'] = $this->expectRejected(
                function () use ($churchA, $classB): void {
                    DB::table('users')->insert([
                        'id' => -1,
                        'church_id' => $churchA,
                        'class_id' => $classB,
                        'name' => 'Verify',
                        'email' => 'verify-cross@verify.invalid',
                        'password' => 'x',
                        'role' => 'member',
                        'application_status' => 'approved',
                    ]);
                },
                'the insert was accepted — a member can be attached to another church\'s class'
            );

            // Must be ACCEPTED: a NULL class is a legitimate state and must not
            // be blocked by the composite key.
            //
            // An explicit id is used so the probe never depends on the
            // users_id_seq value. A database loaded from a fixture with
            // explicit ids can have a sequence that is still at 1, and the
            // probe would then fail with a primary-key collision that has
            // nothing to do with tenant isolation.
            $results['NULL class_id (legitimate) is allowed'] = $this->expectAccepted(
                function () use ($churchA): void {
                    DB::table('users')->insert($this->probeUser($churchA, null));
                },
                'a member with no class was rejected — MATCH SIMPLE is not behaving as nullable-safe'
            );

            // Must be ACCEPTED: a same-church pair still works.
            $results['same-church user -> own class'] = $this->expectAccepted(
                function () use ($churchA, $classA): void {
                    DB::table('users')->insert($this->probeUser($churchA, $classA, 2));
                },
                'a legitimate same-church assignment was rejected'
            );
        } finally {
            DB::rollBack();
        }

        return $results;
    }

    /**
     * A throwaway user row for the behavioural probes.
     *
     * The id is negative so it can never collide with a real row, whatever the
     * sequence happens to be, and so a probe can never be mistaken for real
     * data if the surrounding transaction were ever not rolled back.
     *
     * @return array<string, mixed>
     */
    private function probeUser(int $churchId, ?int $classId, int $ordinal = 1): array
    {
        return [
            'id' => -$ordinal,
            'church_id' => $churchId,
            'class_id' => $classId,
            'name' => 'Schema Probe',
            'email' => 'schema-probe-'.$ordinal.'@verify.invalid',
            'password' => 'x',
            'role' => 'member',
            'application_status' => 'approved',
        ];
    }

    /**
     * @param  callable(): void  $write
     * @return array{ok: bool, detail: string}
     */
    private function expectRejected(callable $write, string $acceptedDetail): array
    {
        return $this->probe($write, false, $acceptedDetail);
    }

    /**
     * @param  callable(): void  $write
     * @return array{ok: bool, detail: string}
     */
    private function expectAccepted(callable $write, string $rejectedDetail): array
    {
        return $this->probe($write, true, $rejectedDetail);
    }

    /**
     * Run one write inside its own SAVEPOINT.
     *
     * The savepoint is essential on PostgreSQL: a statement that violates a
     * constraint aborts the WHOLE transaction, so without one, the first
     * expected rejection would make every later probe fail with
     * "current transaction is aborted" — reporting a schema violation that
     * does not exist. Rolling back to the savepoint clears that state while
     * keeping the outer transaction alive for the remaining probes.
     *
     * @param  callable(): void  $write
     * @return array{ok: bool, detail: string}
     */
    private function probe(callable $write, bool $expectSuccess, string $failureDetail): array
    {
        $name = 'tenant_verify_'.substr(md5($failureDetail.uniqid('', true)), 0, 12);

        DB::statement('SAVEPOINT '.$name);

        try {
            $write();
            DB::statement('RELEASE SAVEPOINT '.$name);
        } catch (\Throwable $e) {
            // Roll back to the savepoint so the transaction is usable again.
            DB::statement('ROLLBACK TO SAVEPOINT '.$name);

            try {
                DB::statement('RELEASE SAVEPOINT '.$name);
            } catch (\Throwable) {
                // Some drivers have no RELEASE in this state; harmless.
            }

            if ($expectSuccess) {
                return [
                    'ok' => false,
                    'detail' => $failureDetail.' ('.$e->getMessage().')',
                ];
            }

            return ['ok' => true, 'detail' => 'rejected as expected'];
        }

        if ($expectSuccess) {
            return ['ok' => true, 'detail' => 'accepted as expected'];
        }

        return ['ok' => false, 'detail' => $failureDetail];
    }

    /**
     * @return array{exists: bool, matches: bool, onUpdateCascade: bool, notValid: bool}
     */
    private function describeConstraint(string $table, string $name): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite does not expose constraint names via PRAGMA, so the DDL
            // is the only source of truth.
            $ddl = DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );

            $sql = is_object($ddl) ? $this->strProp($ddl, 'sql') : '';

            return [
                'exists' => (bool) preg_match('/CONSTRAINT\s+'.preg_quote($name, '/').'\s+FOREIGN KEY/i', $sql),
                'matches' => (bool) preg_match('/CONSTRAINT\s+'.preg_quote($name, '/').'\s+FOREIGN KEY\s*\(church_id,\s*\w+\)/i', $sql),
                'onUpdateCascade' => (bool) preg_match(
                    '/CONSTRAINT\s+'.preg_quote($name, '/').'.*?ON UPDATE CASCADE/is',
                    $sql
                ),
                // SQLite has no NOT VALID concept: it checks foreign keys at
                // DML time, so a constraint is always fully in force.
                'notValid' => false,
            ];
        }

        $row = DB::selectOne(
            'SELECT c.confupdtype, c.convalidated,
                    (SELECT string_agg(a.attname, \',\' ORDER BY k.ord)
                       FROM unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord)
                       JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum) AS cols,
                    (SELECT string_agg(a.attname, \',\') FROM pg_attribute a
                      WHERE a.attrelid = c.confrelid AND a.attnum = ANY(c.confkey)) AS refnames
               FROM pg_constraint c
              WHERE c.contype = \'f\' AND c.conname = ? AND c.conrelid = ?::regclass',
            [$name, $table]
        );

        if (! is_object($row)) {
            return ['exists' => false, 'matches' => false, 'onUpdateCascade' => false, 'notValid' => false];
        }

        $cols = $this->strProp($row, 'cols');
        $refs = $this->strProp($row, 'refnames');

        return [
            'exists' => true,
            'matches' => str_starts_with($cols, 'church_id,') && str_contains($refs, 'church_id') && str_contains($refs, 'id'),
            // confupdtype 'a' = NO ACTION, 'c' = CASCADE.
            'onUpdateCascade' => $this->strProp($row, 'confupdtype') === 'c',
            // convalidated = false means added NOT VALID: enforced for new
            // writes, but pre-existing rows were not checked.
            'notValid' => ! $this->boolProp($row, 'convalidated'),
        ];
    }

    /**
     * Read one property from a driver result row.
     *
     * DB::select()/selectOne() return `mixed` rows whose property types differ
     * between pdo_pgsql and pdo_sqlite, so each accessor narrows to the shape
     * it needs instead of casting `mixed` directly.
     */
    private function intProp(?object $row, string $name): int
    {
        if ($row === null) {
            return 0;
        }

        $value = $row->{$name} ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function strProp(?object $row, string $name): string
    {
        if ($row === null) {
            return '';
        }

        $value = $row->{$name} ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function boolProp(?object $row, string $name): bool
    {
        if ($row === null) {
            return false;
        }

        $value = $row->{$name} ?? null;

        return is_bool($value) ? $value : (is_scalar($value) && (bool) $value);
    }

    private function hasIndex(string $table, string $index): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return Schema::hasIndex($table, $index);
    }

    private function section(string $title): void
    {
        $this->line("<options=bold>{$title}</>");
    }
}
