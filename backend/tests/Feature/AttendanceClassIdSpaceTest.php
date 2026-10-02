<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceContext;
use App\Models\Church;
use App\Models\Church as ChurchModel;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `attendances.class_year_id` must be recorded in the `classes` id space.
 *
 * The mismatch
 * ------------
 * `2026_06_22_000001` backfilled `attendances.class_year_id` to a `classes.id`
 * and repointed its foreign key at `classes`. It deliberately did NOT repoint
 * `users.class_year_id`, which is still foreign-keyed to the deprecated
 * `class_years` table — it is the one remaining `class_year_id` in the schema
 * that lives in a different id space (see `CreateUserRequest`, where the input
 * is `prohibited` for exactly this reason, and `EventPolicy::eventTargetsServantsClass`,
 * which had to stop comparing the two).
 *
 * `AttendanceService::processAttendance()` wrote:
 *
 *     'class_year_id' => $member->class_year_id ?? $member->class_id,
 *
 * reading a `class_years` id and storing it in a `classes` foreign key. Today
 * the bug is latent: `class_year_id` is `prohibited` on input, so every member
 * has it NULL and the `?? $member->class_id` arm always applies. But the
 * column is nullable and still populated by historical backfills, so any row
 * that carries one — a legacy import, a restored dump, a direct data fix —
 * would either fail the foreign key with a 500 or, worse, silently attach the
 * attendance to a DIFFERENT class whose `classes.id` happens to equal the
 * `class_years.id`.
 *
 * The second outcome is the dangerous one: it is a correct write of the wrong
 * data, produces no error, and corrupts per-class attendance reporting.
 */
class AttendanceClassIdSpaceTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'ClassSpace@1234';

    private Church $church;

    private Stage $stage;

    private Classe $classe;

    private Classe $otherClasse;

    private AttendanceContext $context;

    private User $member;

    private User $servant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = ChurchModel::factory()->create();
        $this->stage = Stage::factory()->forChurch($this->church)->create();
        $this->classe = Classe::factory()->forChurch($this->church)
            ->state(['stage_id' => $this->stage->id])->create(['name' => 'Class A']);
        $this->otherClasse = Classe::factory()->forChurch($this->church)
            ->state(['stage_id' => $this->stage->id])->create(['name' => 'Class B']);

        $this->context = AttendanceContext::factory()
            ->state(['church_id' => $this->church->id])
            ->create();

        $this->member = User::factory()->create([
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'stage_id' => $this->stage->id,
            'role' => UserRole::Member,
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->servant = User::factory()->create([
            'church_id' => $this->church->id,
            'class_id' => $this->classe->id,
            'stage_id' => $this->stage->id,
            'role' => UserRole::Servant,
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    /**
     * `users.class_year_id` really is still foreign-keyed to `class_years`.
     *
     * This is the fact the rest of the file depends on, so it is asserted
     * rather than assumed — if someone finally repoints the column, this test
     * should tell them the guard below is no longer load-bearing.
     */
    public function test_users_class_year_id_still_references_the_deprecated_class_years_table(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->assertTrue(
                DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'") !== null,
                'users table must exist'
            );

            return;
        }

        $target = DB::selectOne(
            "SELECT ccu.table_name AS target
             FROM information_schema.table_constraints tc
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_name = 'users'
               AND tc.constraint_name = 'users_class_year_id_foreign'"
        );

        $this->assertNotNull($target, 'users.class_year_id must still carry its foreign key');
        $this->assertSame(
            'class_years',
            $target->target,
            'users.class_year_id is expected to still point at the deprecated class_years table. '
            .'If this has been repointed at classes, AttendanceService no longer needs a guard here.'
        );
    }

    /**
     * The defect.
     *
     * A member carrying a legacy `class_year_id` whose value collides with a
     * real `classes.id` in the same church must have their attendance recorded
     * against their OWN class, never the class that happens to own that id in
     * the other id space.
     *
     * The fixture reproduces the real legacy shape: `class_years` is still
     * populated and holds an id that happens to equal another class's
     * `classes.id`. That is not a contrived value — the two tables were
     * allocated from independent sequences, so collisions between them are
     * expected, not exceptional.
     */
    public function test_legacy_class_year_id_does_not_redirect_attendance_to_another_class(): void
    {
        $collidingId = $this->otherClasse->id;

        // A real legacy row, so the foreign key on users.class_year_id holds.
        DB::table('class_years')->insert([
            'id' => $collidingId,
            'name' => 'Legacy Year',
            'year' => '2020',
            'is_active' => true,
            'church_id' => $this->church->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $this->member->id)->update([
            'class_year_id' => $collidingId,
        ]);

        $member = User::find($this->member->id);
        $this->assertSame(
            $collidingId,
            $member?->class_year_id,
            'fixture precondition: the legacy column must hold the colliding value'
        );
        $this->assertNotSame(
            $this->classe->id,
            $collidingId,
            'fixture precondition: the collision must point at a DIFFERENT class'
        );

        Sanctum::actingAs($this->servant);

        /** @var AttendanceService $service */
        $service = app(AttendanceService::class);
        $service->recordAttendanceByMemberId(
            memberId: (string) $this->member->member_id,
            recordedBy: $this->servant->id,
            contextId: $this->context->id,
        );

        $attendance = Attendance::query()->where('user_id', $this->member->id)->first();

        $this->assertNotNull($attendance, 'the attendance must have been recorded');
        $this->assertSame(
            $this->classe->id,
            (int) $attendance->class_year_id,
            'Attendance must be recorded against the member\'s own class. Reading '
            .'users.class_year_id here mixes the class_years id space with the classes '
            .'foreign key on attendances and silently misattributes the record.'
        );
    }

    /**
     * The normal path is unaffected.
     */
    public function test_attendance_still_records_the_members_class(): void
    {
        Sanctum::actingAs($this->servant);

        /** @var AttendanceService $service */
        $service = app(AttendanceService::class);
        $service->recordAttendanceByMemberId(
            memberId: (string) $this->member->member_id,
            recordedBy: $this->servant->id,
            contextId: $this->context->id,
        );

        $attendance = Attendance::query()->where('user_id', $this->member->id)->first();

        $this->assertNotNull($attendance);
        $this->assertSame($this->classe->id, (int) $attendance->class_year_id);
    }

    /**
     * A member with no class at all records a null class rather than borrowing
     * one.
     */
    public function test_member_without_a_class_records_a_null_class(): void
    {
        $orphan = User::factory()->create([
            'church_id' => $this->church->id,
            'class_id' => null,
            'stage_id' => $this->stage->id,
            'role' => UserRole::Member,
            'password' => Hash::make(self::PASSWORD),
        ]);

        Sanctum::actingAs($this->servant);

        /** @var AttendanceService $service */
        $service = app(AttendanceService::class);
        $service->recordAttendanceByMemberId(
            memberId: (string) $orphan->member_id,
            recordedBy: $this->servant->id,
            contextId: $this->context->id,
        );

        $attendance = Attendance::query()->where('user_id', $orphan->id)->first();

        $this->assertNotNull($attendance);
        $this->assertNull($attendance->class_year_id);
    }
}
