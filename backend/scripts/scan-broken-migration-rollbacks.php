<?php

/**
 * Lint: flag migrations whose down() cannot deterministically undo their up()
 * on SQLite, specifically the "drop a column while an index still covers it"
 * defect.
 *
 *   SQLSTATE[HY000]: General error: 1 error in index <name> after drop
 *   column: no such column: <col>
 *
 * PostgreSQL hides this: ALTER TABLE ... DROP COLUMN silently removes
 * dependent indexes. These therefore only surface on rollback, and a
 * driver-blind test suite never sees them.
 *
 * This is a HEURISTIC lint for code review, not a proof of correctness — the
 * authoritative check is `tests/Feature/MigrationRollbackTest`, which runs the
 * real migrator. It exists so a new broken down() is spotted in review rather
 * than during an incident.
 *
 * Usage: php scripts/scan-broken-migration-rollbacks.php
 */
$dir = dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
$files = glob($dir.DIRECTORY_SEPARATOR.'*.php');
sort($files);

if ($files === []) {
    fwrite(STDERR, "No migrations found in {$dir}\n");

    exit(2);
}

echo 'Scanned '.count($files)." migrations.\n\n";

$findings = [];

/** Extract a method body by brace matching. */
$extract = static function (string $src, string $method): string {
    if (! preg_match('/function\s+'.$method.'\s*\(\s*\)\s*:\s*void/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 0;
    $i = $start;
    $len = strlen($src);
    for (; $i < $len; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }
    }

    return substr($src, $start, $i - $start);
};

foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    $up = $extract($src, 'up');
    $down = $extract($src, 'down');

    if ($up === '' || $down === '') {
        continue;
    }

    /*
     * Indexes created in up(), keyed by the column(s) they cover.
     *
     * Two forms matter:
     *   1. explicit:  $table->index('col') / $table->unique(['a','b'], 'name')
     *   2. implicit:  $table->string('col')->unique()  — a chain on the column
     *                 definition. This is the one that is easy to miss: the
     *                 index exists but no `index()` call names it.
     */
    /*
     * Indexes created in up(), keyed by the column(s) they cover.
     *
     * Two forms matter:
     *   1. explicit:  $table->index('col') / $table->unique(['a','b'], 'name')
     *   2. implicit:  $table->string('col')->unique()  — a chain on the column
     *                 definition. This is the one that is easy to miss: the
     *                 index exists but no `index()` call names it.
     *
     * A column-only index()/unique() call still gets a deterministic generated
     * name (`<table>_<col>_index` / `<table>_<col>_unique`), so those names are
     * reconstructed here. That matters because a down() which drops the index
     * BY NAME must be credited as removing it.
     */
    $indexesOn = [];

    /** Nearest enclosing Schema::table()/Schema::create() table name before $offset. */
    $tableAt = static function (string $haystack, int $offset): string {
        $before = substr($haystack, 0, $offset);
        if (preg_match_all("/Schema::(?:table|create)\(\s*'([^']+)'/", $before, $tm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $tm[count($tm) - 1][1][0];
        }

        return '{table}';
    };

    if (preg_match_all(
        "/\\\$table->(index|unique)\(\s*(\[[^\]]*\]|'[^']*')(?:\s*,\s*'([^']*)')?/",
        $up,
        $m,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        foreach ($m as $set) {
            $kind = $set[1][0];
            $rawCols = $set[2][0];
            $explicit = isset($set[3]) ? $set[3][0] : '';
            $offset = $set[2][1];
            $table = $tableAt($up, $offset);

            foreach (explode(',', trim($rawCols, "[]'\"")) as $c) {
                $c = trim(trim($c), "'\"");
                if ($c === '') {
                    continue;
                }
                $name = $explicit !== ''
                    ? $explicit
                    : $table.'_'.$c.'_'.($kind === 'unique' ? 'unique' : 'index');
                $indexesOn[$c][] = $name;
            }
        }
    }

    // Implicit unique on a column definition, e.g. $table->string('a', 20)->unique();
    if (preg_match_all(
        "/\\\$table->\w+\(\s*'([^']+)'[^)]*\)(?:->\w+\([^)]*\))*?->unique\(\s*\)/",
        $up,
        $m,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        foreach ($m as $set) {
            $col = $set[1][0];
            $table = $tableAt($up, $set[0][1]);
            $indexesOn[$col][] = $table.'_'.$col.'_unique';
        }
    }

    if ($indexesOn === []) {
        continue;
    }

    // Columns removed by down().
    $dropped = [];
    if (preg_match_all("/\\\$table->drop(?:Column|ConstrainedForeignId)\(\s*(?:'([^']+)'|\[([^\]]*)\])/", $down, $m, PREG_SET_ORDER)) {
        foreach ($m as $set) {
            $cols = $set[1] !== '' ? [$set[1]] : array_map(
                static fn (string $c): string => trim(trim($c), "'\" "),
                explode(',', $set[2])
            );
            foreach ($cols as $c) {
                if ($c !== '') {
                    $dropped[] = $c;
                }
            }
        }
    }

    if ($dropped === []) {
        continue;
    }

    // Every dropIndex()/dropUnique() target named anywhere in down().
    $removed = [];
    if (preg_match_all("/\\\$table->drop(?:Index|Unique)\(\s*([^)]*)\)/", $down, $m, PREG_SET_ORDER)) {
        foreach ($m as $set) {
            $removed[] = $set[1];
        }
    }

    foreach ($dropped as $col) {
        if (! isset($indexesOn[$col])) {
            continue;
        }

        /*
         * Count first. Name matching cannot distinguish an auto-named index
         * from a sibling index on the same column, so the reliable signal is
         * that up() creates more index objects on this column than down()
         * removes. If up() created `->unique()` and `->index()` and down()
         * calls dropIndex() once, one index survives and SQLite rejects the
         * DROP COLUMN.
         *
         * A drop counts as covering the column when it either names the
         * column, or names an index that was explicitly created on it.
         */
        $credited = 0;
        foreach ($removed as $target) {
            $covers = str_contains($target, "'".$col."'") || str_contains($target, '"'.$col.'"');

            if (! $covers) {
                foreach ($indexesOn[$col] as $indexName) {
                    if (str_contains($target, $indexName)) {
                        $covers = true;
                        break;
                    }
                }
            }

            if ($covers) {
                $credited++;
            }
        }

        if ($credited >= count($indexesOn[$col])) {
            continue;
        }

        $findings[] = [
            'migration' => basename($file),
            'column' => $col,
            'indexes' => $indexesOn[$col],
            'note' => sprintf(
                'up() creates %d index object(s) on this column, down() removes %d',
                count($indexesOn[$col]),
                $credited
            ),
        ];
    }
}

if ($findings === []) {
    echo "OK — no migration drops an indexed column without dropping every index on it.\n";

    exit(0);
}

echo count($findings)." migration(s) with a down() that cannot deterministically undo up() on SQLite:\n\n";
foreach ($findings as $f) {
    echo "  {$f['migration']}\n";
    echo "      drops column : {$f['column']}\n";
    echo '      indexes on it: '.implode(' | ', $f['indexes'])."\n";
    echo "      {$f['note']}\n\n";
}

echo "Authoritative verification: php artisan test --filter=MigrationRollbackTest\n";
