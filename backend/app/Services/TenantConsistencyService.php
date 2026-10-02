<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspects and optionally repairs cross-tenant inconsistencies.
 *
 * A "cross-tenant inconsistency" is a row on a tenant-owned child table that
 * claims one church (`church_id = A`) while pointing at a parent resource that
 * belongs to another (`<parent>.church_id = B`). Once
 * `2026_09_29_000001_add_composite_tenant_foreign_keys` is applied, PostgreSQL
 * refuses to store such a row, so any row still present at that point is
 * legacy data that predates the constraint.
 *
 * Why this is a service and not inline SQL
 * ---------------------------------------
 * The composite-FK migration previously repaired legacy rows with raw SQL
 * containing `church_id IS NOT (SELECT ...)`. That is valid SQLite and
 * INVALID PostgreSQL, so the migration aborted on the production driver and the
 * constraints were never created. The repair is now expressed as portable
 * standard SQL plus explicit per-row updates, which additionally makes the
 * repair observable and non-destructive.
 *
 * Repair semantics (deliberate, and the reason `--repair` is opt-in)
 * ---------------------------------------------------------------
 *  - The PARENT is authoritative. The child's `church_id` is aligned to the
 *    parent it points at. The alternative — re-pointing the child at a
 *    same-church parent — would require guessing an intent the data does not
 *    contain.
 *  - Repairs run in dependency order (parents before children), so a class is
 *    aligned to its stage before the users pointing at that class are aligned
 *    to it. The result is deterministic and idempotent.
 *  - ORPHANS (child column set, parent row missing) are never modified. Their
 *    `church_id` is NOT nulled out: an earlier version of the migration did
 *    exactly that, because the subquery returned NULL for a missing parent,
 *    which silently destroyed tenant ownership.
 *  - A child whose parent has a NULL `church_id` is never modified either.
 *
 * This is a genuine cross-tenant data mutation, so it is never performed
 * implicitly by the migration. See `TenantAuditCommand`.
 */
class TenantConsistencyService
{
    /**
     * The child -> parent relationships the composite tenant FKs enforce.
     *
     * Order is significant for repair: a parent must be aligned before the
     * children that point at it.
     *
     * @var array<int, array{child: string, column: string, parent: string, constraint: string}>
     */
    public const RELATIONSHIPS = [
        ['child' => 'classes', 'column' => 'stage_id', 'parent' => 'stages', 'constraint' => 'classes_church_stage_fk'],
        ['child' => 'users', 'column' => 'stage_id', 'parent' => 'stages', 'constraint' => 'users_church_stage_fk'],
        ['child' => 'users', 'column' => 'class_id', 'parent' => 'classes', 'constraint' => 'users_church_class_fk'],
        ['child' => 'events', 'column' => 'class_year_id', 'parent' => 'classes', 'constraint' => 'events_church_class_fk'],
        ['child' => 'event_targets', 'column' => 'class_id', 'parent' => 'classes', 'constraint' => 'event_targets_church_class_fk'],
        ['child' => 'qr_invites', 'column' => 'stage_id', 'parent' => 'stages', 'constraint' => 'qr_invites_church_stage_fk'],
        ['child' => 'qr_invites', 'column' => 'class_id', 'parent' => 'classes', 'constraint' => 'qr_invites_church_class_fk'],
    ];

    /**
     * How many offending rows to return per relationship.
     *
     * The audit needs IDs to be actionable, but a broken database can hold
     * millions of them. Returning every id would produce an unreadable report
     * and a memory spike, so the list is capped and the true total is always
     * reported separately.
     */
    private const SAMPLE_LIMIT = 50;

    /**
     * Rows updated per query while repairing.
     */
    private const REPAIR_CHUNK = 500;

    /**
     * Describe every relationship without touching any data.
     *
     * @return array<int, array{
     *     child: string, column: string, parent: string, constraint: string,
     *     mismatched: int, unowned: int, orphaned: int,
     *     samples: array<int, array{id: int|string|null, column: int|string|null, church_id: int|string|null, parent_church_id: int|string|null, reason: string}>
     * }>
     */
    public function inspect(): array
    {
        $report = [];

        foreach (self::RELATIONSHIPS as $relationship) {
            if (! $this->isApplicable($relationship)) {
                continue;
            }

            $report[] = [
                ...$relationship,
                'mismatched' => $this->countWhere($this->mismatchedQuery($relationship)),
                'unowned' => $this->countWhere($this->unownedQuery($relationship)),
                'orphaned' => $this->countWhere($this->orphanedQuery($relationship)),
                'samples' => $this->samples($relationship),
            ];
        }

        return $report;
    }

    /**
     * Total number of rows that would be modified by {@see repair()}.
     *
     * `orphaned` rows are excluded: they are never modified, so counting them
     * would overstate what a repair does.
     */
    public function countRepairable(): int
    {
        $total = 0;

        foreach ($this->inspect() as $relationship) {
            $total += $relationship['mismatched'] + $relationship['unowned'];
        }

        return $total;
    }

    /**
     * Align each child's `church_id` to the parent it points at.
     *
     * Safe by construction: orphans and parents without a church are skipped,
     * so `church_id` is never set to NULL and no row is moved unless a real
     * parent row dictates the destination.
     *
     * @return array<int, array{child: string, column: string, updated: int}>
     */
    public function repair(): array
    {
        $result = [];

        foreach (self::RELATIONSHIPS as $relationship) {
            if (! $this->isApplicable($relationship)) {
                continue;
            }

            $result[] = [
                ...$relationship,
                'updated' => $this->repairRelationship($relationship),
            ];
        }

        return $result;
    }

    /**
     * @param  array{child: string, column: string, parent: string, constraint: string}  $relationship
     * @return array<int, array{id: int|string|null, column: int|string|null, church_id: int|string|null, parent_church_id: int|string|null, reason: string}>
     */
    private function samples(array $relationship): array
    {
        $rows = $this->mismatchedSamples($relationship);

        if (count($rows) >= self::SAMPLE_LIMIT) {
            return $rows;
        }

        $rows = [...$rows, ...$this->unownedSamples($relationship)];

        return array_slice($rows, 0, self::SAMPLE_LIMIT);
    }

    /**
     * @param  array{child: string, column: string, parent: string, constraint: string}  $relationship
     * @return array<int, array{id: int|string|null, column: int|string|null, church_id: int|string|null, parent_church_id: int|string|null, reason: string}>
     */
    private function mismatchedSamples(array $relationship): array
    {
        return $this->mismatchedQuery($relationship)
            ->join(
                $relationship['parent'].' as parent',
                'parent.id',
                '=',
                $relationship['child'].'.'.$relationship['column']
            )
            ->orderBy($relationship['child'].'.id')
            ->limit(self::SAMPLE_LIMIT)
            ->get([
                $relationship['child'].'.id as id',
                $relationship['child'].'.'.$relationship['column'].' as column',
                $relationship['child'].'.church_id as church_id',
                'parent.church_id as parent_church_id',
            ])
            ->map(static fn (object $row): array => [
                'id' => self::scalar($row->id),
                'column' => self::scalar($row->column),
                'church_id' => self::scalar($row->church_id),
                'parent_church_id' => self::scalar($row->parent_church_id),
                'reason' => 'cross-church',
            ])->all();
    }

    /**
     * @param  array{child: string, column: string, parent: string, constraint: string}  $relationship
     * @return array<int, array{id: int|string|null, column: int|string|null, church_id: int|string|null, parent_church_id: int|string|null, reason: string}>
     */
    private function unownedSamples(array $relationship): array
    {
        return $this->unownedQuery($relationship)
            ->join(
                $relationship['parent'].' as parent',
                'parent.id',
                '=',
                $relationship['child'].'.'.$relationship['column']
            )
            ->orderBy($relationship['child'].'.id')
            ->limit(self::SAMPLE_LIMIT)
            ->get([
                $relationship['child'].'.id as id',
                $relationship['child'].'.'.$relationship['column'].' as column',
                $relationship['child'].'.church_id as church_id',
                'parent.church_id as parent_church_id',
            ])
            ->map(static fn (object $row): array => [
                'id' => self::scalar($row->id),
                'column' => self::scalar($row->column),
                'church_id' => self::scalar($row->church_id),
                'parent_church_id' => self::scalar($row->parent_church_id),
                'reason' => 'no church',
            ])->all();
    }

    /**
     * @param  array{child: string, column: string, parent: string, constraint: string}  $relationship
     */
    private function repairRelationship(array $relationship): int
    {
        $updated = 0;

        // Mismatched: parent exists, both churches set, and they disagree.
        // Unowned:    parent exists and owns a church, child has none.
        $select = DB::table($relationship['child'])
            ->join(
                $relationship['parent'].' as parent',
                'parent.id',
                '=',
                $relationship['child'].'.'.$relationship['column']
            )
            ->whereNotNull($relationship['child'].'.'.$relationship['column'])
            ->whereNotNull('parent.church_id')
            ->where(function (Builder $query) use ($relationship): void {
                $query
                    // child.church_id IS NULL  OR  child.church_id <> parent.church_id
                    ->whereNull($relationship['child'].'.church_id')
                    ->orWhere($relationship['child'].'.church_id', '!=', DB::raw('parent.church_id'));
            })
            ->orderBy($relationship['child'].'.id')
            ->select([
                $relationship['child'].'.id as id',
                'parent.church_id as parent_church_id',
            ]);

        $select->chunk(self::REPAIR_CHUNK, function ($rows) use ($relationship, &$updated): void {
            $byDestination = [];

            foreach ($rows as $row) {
                $destination = self::scalar($row->parent_church_id);
                $id = self::scalar($row->id);

                // `parent.church_id IS NOT NULL` is part of the query, so a
                // null destination would mean the driver disagreed with the
                // WHERE clause. Skipping is the safe response: writing NULL
                // here is precisely the data loss this service exists to
                // prevent.
                if ($destination === null || ! is_int($destination) || $id === null || ! is_int($id)) {
                    continue;
                }

                $byDestination[$destination][] = $id;
            }

            foreach ($byDestination as $destination => $ids) {
                $updated += DB::table($relationship['child'])
                    ->whereIn('id', $ids)
                    ->update(['church_id' => $destination]);
            }
        });

        return $updated;
    }

    /**
     * Child claims a church, but the parent it points at belongs to another.
     *
     * @param  array{child: string, column: string, parent: string, constraint: string}  $relationship
     */
    private function mismatchedQuery(array $relationship): Builder
    {
        return DB::table($relationship['child'])
            ->whereNotNull($relationship['child'].'.'.$relationship['column'])
            ->whereNotNull($relationship['child'].'.church_id')
            ->whereExists(function (Builder $query) use ($relationship): void {
                $query->select(DB::raw(1))
                    ->from($relationship['parent'].' as parent')
                    ->whereColumn('parent.id', $relationship['child'].'.'.$relationship['column'])
                    ->whereNotNull('parent.church_id')
                    // Both sides are non-null, so <> is a plain value comparison.
                    ->whereColumn('parent.church_id', '!=', $relationship['child'].'.church_id');
            });
    }

    /**
     * Child has no church at all, but the parent it points at owns one.
     *
     * @param  array{child: string, column: string, parent: string, constraint: string}  $relationship
     */
    private function unownedQuery(array $relationship): Builder
    {
        return DB::table($relationship['child'])
            ->whereNotNull($relationship['child'].'.'.$relationship['column'])
            ->whereNull($relationship['child'].'.church_id')
            ->whereExists(function (Builder $query) use ($relationship): void {
                $query->select(DB::raw(1))
                    ->from($relationship['parent'].' as parent')
                    ->whereColumn('parent.id', $relationship['child'].'.'.$relationship['column'])
                    ->whereNotNull('parent.church_id');
            });
    }

    /**
     * Child points at a parent row that does not exist.
     *
     * Reported for visibility only. These rows are never modified, because
     * there is no parent to take `church_id` from and blanking it would
     * destroy the only remaining tenant information.
     *
     * @param  array{child: string, column: string, parent: string, constraint: string}  $relationship
     */
    private function orphanedQuery(array $relationship): Builder
    {
        return DB::table($relationship['child'])
            ->whereNotNull($relationship['child'].'.'.$relationship['column'])
            ->whereNotExists(function (Builder $query) use ($relationship): void {
                $query->select(DB::raw(1))
                    ->from($relationship['parent'].' as parent')
                    ->whereColumn('parent.id', $relationship['child'].'.'.$relationship['column']);
            });
    }

    /**
     * Normalise a value read back from the driver.
     *
     * The driver hands back whatever native type the column has, which differs
     * between pdo_pgsql (int/string) and pdo_sqlite (int/string) and is
     * `mixed` to static analysis. Every id in this service is a scalar that
     * fits an integer, so anything unexpected is stringified rather than
     * silently coerced, and the int path is the one used for the repair.
     */
    private static function scalar(mixed $value): int|string|null
    {
        if ($value === null || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value) || is_bool($value)) {
            return (int) $value;
        }

        return (string) $value; // @phpstan-ignore-line unreachable for scalar columns
    }

    private function countWhere(Builder $query): int
    {
        return (int) $query->count();
    }

    /**
     * @param  array{child: string, column: string, parent: string, constraint: string}  $relationship
     */
    private function isApplicable(array $relationship): bool
    {
        if (! Schema::hasTable($relationship['child']) || ! Schema::hasTable($relationship['parent'])) {
            return false;
        }

        if (! Schema::hasColumn($relationship['child'], 'church_id')) {
            return false;
        }

        return Schema::hasColumn($relationship['child'], $relationship['column'])
            && Schema::hasColumn($relationship['parent'], 'church_id')
            && Schema::hasColumn($relationship['parent'], 'id');
    }
}
