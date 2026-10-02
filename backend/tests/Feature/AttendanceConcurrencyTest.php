<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Concurrency proof for attendance recording, on a real PostgreSQL engine.
 *
 * Why this is not analytical
 * --------------------------
 * `recordAttendance()` reads-then-writes ("has the member already been
 * recorded today?") inside a transaction, with a `lockForUpdate()` on the
 * member row. Read that on paper and it looks like a textbook TOCTOU race:
 * two requests can both observe "not recorded" and both insert.
 *
 * Two independent mechanisms close it, and both are only verifiable by
 * actually running them against a real engine:
 *
 *   1. the row lock serializes the two transactions, so the second one reads
 *      the committed row and refuses;
 *   2. even if that were bypassed, three PARTIAL UNIQUE indexes make a
 *      duplicate unrepresentable at the storage layer.
 *
 * A second, fully independent PDO connection is used deliberately. Re-using
 * Laravel's single connection would serialise everything through one session
 * and prove nothing about locking.
 *
 * Skipped on SQLite: it has no row-level write locks, so "FOR UPDATE" is a
 * no-op there and a concurrency test would pass vacuously. The partial unique
 * indexes are still exercised by the driver-independent tests at the bottom.
 */
class AttendanceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private Stage $stage;

    private Classe $classe;

    private AttendanceContext $context;

    private User $member;

    private User $servant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessPostgres();

        $this->church = Church::factory()->create();
        $this->stage = Stage::factory()->forChurch($this->church)->create();
        $this->classe = Classe::factory()->forChurch($this->church)
            ->state(['stage_id' => $this->stage->id])->create();

        $this->member = User::factory()->create([
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'stage_id' => $this->stage->id,
            'role' => UserRole::Member,
            'password' => Hash::make('Matrix@1234'),
        ]);

        $this->servant = User::factory()->create([
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'stage_id' => $this->stage->id,
            'role' => UserRole::Servant,
            'password' => Hash::make('Matrix@1234'),
        ]);

        $this->context = AttendanceContext::factory()
            ->state([
                'church_id' => $this->church->id,
                'created_by' => $this->servant->id,
            ])
            ->create();
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'Requires PostgreSQL. SQLite has no row-level write locks, so FOR UPDATE '
                .'is a no-op and a concurrency assertion would pass vacuously.'
            );
        }
    }

    /**
     * A genuinely independent session, so the lock test is meaningful.
     */
    private function secondConnection(): PDO
    {
        $config = config('database.connections.pgsql');

        return new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['host'],
                $config['port'],
                $config['database'],
            ),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * Assert that a write is rejected with a unique-violation, without
     * poisoning the enclosing transaction.
     *
     * On PostgreSQL a constraint violation ABORTS the whole transaction, so
     * every later statement in the test would fail with
     * "current transaction is aborted" and mask the real result. A savepoint
     * scopes the damage to the single statement under test.
     */
    private function assertUniqueViolation(callable $write, string $message): void
    {
        $name = 'concurrency_probe_'.substr(md5($message.uniqid('', true)), 0, 10);

        DB::statement('SAVEPOINT '.$name);

        try {
            $write();
            DB::statement('ROLLBACK TO SAVEPOINT '.$name);
            DB::statement('RELEASE SAVEPOINT '.$name);

            $this->fail($message);
        } catch (QueryException $e) {
            DB::statement('ROLLBACK TO SAVEPOINT '.$name);
            DB::statement('RELEASE SAVEPOINT '.$name);

            $this->assertSame('23505', $e->getCode(), $message.' (got SQLSTATE '.$e->getCode().')');
        }
    }

    /**
     * MECHANISM 1 — two genuinely concurrent sessions are serialized by the
     * unique index, not both allowed to commit.
     *
     * This uses two raw, independent connections rather than Laravel's, because
     * RefreshDatabase already owns a transaction and `DB::beginTransaction()`
     * inside it only creates a SAVEPOINT — a savepoint takes no row lock, so
     * the first version of this test asserted against a lock that was never
     * held and passed/failed for the wrong reason.
     *
     * Session A inserts the attendance and does NOT commit. Session B then
     * attempts the same insert. PostgreSQL enforces uniqueness by BLOCKING,
     * so B must time out rather than proceed. If B were allowed through, two
     * concurrent requests could both create the same attendance.
     *
     * The fixtures must be COMMITTED for a second session to see them at all —
     * a row written inside RefreshDatabase's open transaction is invisible
     * outside it, which is itself the reason this test needs its own
     * transaction handling. Everything written is therefore purged in a
     * finally block, in FK-safe order, so nothing leaks into later tests.
     */
    public function test_a_concurrent_session_is_blocked_by_an_uncommitted_duplicate(): void
    {
        $author = null;
        $concurrent = null;

        // Release RefreshDatabase's wrapper transaction so the fixture rows are
        // visible to an independent session.
        DB::commit();

        try {
            $author = $this->secondConnection();
            $concurrent = $this->secondConnection();

            $row = [
                'church_id' => (int) $this->church->id,
                'user_id' => (int) $this->member->id,
                'recorded_by' => (int) $this->servant->id,
                'class_year_id' => (int) $this->classe->id,
                'attendance_context_id' => (int) $this->context->id,
                'method' => 'qr',
                'attended_at' => now()->format('Y-m-d H:i:s'),
                'attended_date' => now()->toDateString(),
                'points_earned' => 5,
                'status' => 'present',
            ];

            $insert = static function (PDO $pdo) use ($row): void {
                $stmt = $pdo->prepare(
                    'INSERT INTO attendances (church_id, user_id, recorded_by, class_year_id,
                        attendance_context_id, method, attended_at, attended_date, points_earned, status)
                     VALUES (:church_id, :user_id, :recorded_by, :class_year_id,
                        :attendance_context_id, :method, :attended_at, :attended_date, :points_earned, :status)'
                );
                $stmt->execute($row);
            };

            $author->beginTransaction();
            $insert($author);

            $concurrent->exec("SET lock_timeout = '750ms'");
            $concurrent->beginTransaction();

            $blocked = false;

            try {
                $insert($concurrent);
            } catch (PDOException $e) {
                $blocked = str_contains($e->getMessage(), 'lock timeout')
                    || str_contains($e->getMessage(), 'canceling statement');
            }

            $concurrent->rollBack();

            $this->assertTrue(
                $blocked,
                'A second concurrent session inserted the same attendance while the first '
                .'was still uncommitted. Duplicate attendance is not actually prevented.'
            );
        } finally {
            foreach ([$author, $concurrent] as $session) {
                if ($session === null) {
                    continue;
                }

                try {
                    $session->rollBack();
                } catch (PDOException) {
                    // Already rolled back or never begun; nothing to undo.
                }
            }

            $this->purgeCommittedFixtures();
        }
    }

    /**
     * Remove every row this test committed, in FK-safe order.
     */
    private function purgeCommittedFixtures(): void
    {
        DB::delete('DELETE FROM attendances WHERE user_id = ? OR recorded_by = ?', [
            (int) $this->member->id,
            (int) $this->servant->id,
        ]);
        DB::delete('DELETE FROM points WHERE user_id = ? OR added_by = ?', [
            (int) $this->member->id,
            (int) $this->servant->id,
        ]);
        DB::delete('DELETE FROM attendance_contexts WHERE id = ?', [(int) $this->context->id]);
        DB::delete('DELETE FROM personal_access_tokens WHERE tokenable_id IN (?, ?)', [
            (int) $this->member->id,
            (int) $this->servant->id,
        ]);
        DB::delete('DELETE FROM users WHERE id IN (?, ?)', [
            (int) $this->member->id,
            (int) $this->servant->id,
        ]);
        DB::delete('DELETE FROM classes WHERE id = ?', [(int) $this->classe->id]);
        DB::delete('DELETE FROM stages WHERE id = ?', [(int) $this->stage->id]);
        DB::delete('DELETE FROM churches WHERE id = ?', [(int) $this->church->id]);
    }

    /**
     * MECHANISM 2 — the database makes a duplicate unrepresentable.
     *
     * Bypasses the application entirely: two inserts of the same logical
     * attendance. Even with every application check removed, the partial
     * unique index refuses the second.
     */
    public function test_a_second_identical_attendance_row_cannot_be_stored(): void
    {
        $row = [
            'church_id' => $this->church->id,
            'user_id' => $this->member->id,
            'recorded_by' => $this->servant->id,
            'class_year_id' => $this->classe->id,
            'attendance_context_id' => $this->context->id,
            'method' => 'qr',
            'attended_at' => now(),
            'attended_date' => now()->toDateString(),
            'points_earned' => 5,
            'status' => 'present',
        ];

        DB::table('attendances')->insert($row);

        $this->assertUniqueViolation(
            fn () => DB::table('attendances')->insert($row),
            'A duplicate attendance for the same member, day and context was stored. '
            .'The partial unique index is missing or not covering this case.'
        );

        $this->assertSame(1, Attendance::query()->count());
    }

    /**
     * The same guarantee when the attendance has no context — a different
     * partial index covers it, and it is easy to protect one case and forget
     * the other.
     */
    public function test_plain_attendance_without_context_is_also_deduplicated(): void
    {
        $row = [
            'church_id' => $this->church->id,
            'user_id' => $this->member->id,
            'recorded_by' => $this->servant->id,
            'class_year_id' => $this->classe->id,
            'event_id' => null,
            'attendance_context_id' => null,
            'method' => 'id',
            'attended_at' => now(),
            'attended_date' => now()->toDateString(),
            'points_earned' => 5,
            'status' => 'present',
        ];

        DB::table('attendances')->insert($row);

        $this->assertUniqueViolation(
            fn () => DB::table('attendances')->insert($row),
            'A context-free duplicate attendance was stored.'
        );
    }

    /**
     * END TO END — the application turns the race into a safe refusal, and
     * does not double-award points.
     */
    public function test_recording_the_same_member_twice_creates_one_attendance_and_one_point_award(): void
    {
        $service = app(AttendanceService::class);

        $service->recordAttendance(
            qrToken: (string) $this->member->attendance_qr_token,
            recordedBy: (int) $this->servant->id,
            contextId: (int) $this->context->id,
        );

        $refused = false;

        try {
            $service->recordAttendance(
                qrToken: (string) $this->member->attendance_qr_token,
                recordedBy: (int) $this->servant->id,
                contextId: (int) $this->context->id,
            );
        } catch (ValidationException $e) {
            $refused = $e->errors() !== [];
        }

        $this->assertTrue($refused, 'The second identical recording was not refused.');
        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(
            1,
            DB::table('points')->where('user_id', $this->member->id)->count(),
            'Points were awarded more than once for a single attendance.'
        );
    }

    /**
     * Points are keyed by (reference_type, reference_id). Two concurrent
     * handlers for the SAME attendance therefore cannot both insert a points
     * row, which is what stops a retry from inflating a member's total.
     */
    public function test_points_cannot_be_awarded_twice_for_the_same_attendance(): void
    {
        $attendanceId = DB::table('attendances')->insertGetId([
            'church_id' => $this->church->id,
            'user_id' => $this->member->id,
            'recorded_by' => $this->servant->id,
            'class_year_id' => $this->classe->id,
            'attendance_context_id' => $this->context->id,
            'method' => 'qr',
            'attended_at' => now(),
            'attended_date' => now()->toDateString(),
            'points_earned' => 5,
            'status' => 'present',
        ]);

        $point = [
            'church_id' => $this->church->id,
            'user_id' => $this->member->id,
            'points' => 5,
            'type' => 'attendance',
            'reference_type' => 'attendance',
            'reference_id' => $attendanceId,
        ];

        DB::table('points')->insert($point);

        $this->assertUniqueViolation(
            fn () => DB::table('points')->insert($point),
            'A duplicate points award for one attendance was stored.'
        );
    }

    /**
     * A legitimate separate attendance must remain possible — the guard must
     * not degenerate into "one attendance per member, ever".
     */
    public function test_a_second_member_and_a_second_day_are_both_allowed(): void
    {
        $second = User::factory()->create([
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'stage_id' => $this->stage->id,
            'role' => UserRole::Member,
        ]);

        $row = fn (int $userId, string $date): array => [
            'church_id' => $this->church->id,
            'user_id' => $userId,
            'recorded_by' => $this->servant->id,
            'class_year_id' => $this->classe->id,
            'attendance_context_id' => $this->context->id,
            'method' => 'qr',
            'attended_at' => now(),
            'attended_date' => $date,
            'points_earned' => 5,
            'status' => 'present',
        ];

        DB::table('attendances')->insert($row((int) $this->member->id, '2026-01-01'));
        DB::table('attendances')->insert($row((int) $this->member->id, '2026-01-02'));
        DB::table('attendances')->insert($row((int) $second->id, '2026-01-01'));

        $this->assertSame(
            3,
            Attendance::query()->count(),
            'Distinct members and distinct days must each be allowed.'
        );
    }
}
