<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Classe;
use App\Models\Stage;
use App\Services\TenantConsistencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F-11 — the composite tenant foreign keys must exist AND be in force.
 *
 * A foreign key added with `NOT VALID` is enforced for new writes but its
 * pre-existing rows were never checked, so "the constraint exists" is not the
 * same statement as "the data is validated". The migration adds a constraint
 * NOT VALID only when orphans already exist, and prints the exact promote
 * statement for each one. On a healthy database every constraint must be
 * VALIDATED, and a constraint that is still NOT VALID means the promotion step
 * is outstanding.
 *
 * PostgreSQL execution of this file is BLOCKED in the current environment (no
 * PostgreSQL server is available locally); the command and the catalogue
 * inspection are driver-aware and run on SQLite, while the
 * `convalidated` check is skipped there.
 */
class TenantSchemaVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enough data for the command's behavioural probes to actually run
        // instead of being skipped.
        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();

        $stageA = Stage::factory()->forChurch($churchA)->create();
        $stageB = Stage::factory()->forChurch($churchB)->create();

        Classe::factory()->forChurch($churchA)->state(['stage_id' => $stageA->id])->create();
        Classe::factory()->forChurch($churchB)->state(['stage_id' => $stageB->id])->create();
    }

    public function test_tenant_verify_schema_reports_no_violations(): void
    {
        $exitCode = Artisan::call('tenant:verify-schema');
        $output = Artisan::output();

        $this->assertSame(
            0,
            $exitCode,
            "tenant:verify-schema reported a tenant schema violation:\n".$output
        );

        $this->assertStringNotContainsString(
            'SKIPPED',
            $output,
            'The behavioural proof was skipped, so the gate proved nothing.'
        );

        $this->assertStringContainsString(
            'All tenant isolation invariants are enforced by the database.',
            $output
        );
    }

    public function test_every_composite_tenant_foreign_key_is_present_and_validated(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL catalogue inspection requires the pgsql driver.');
        }

        foreach (TenantConsistencyService::RELATIONSHIPS as $relationship) {
            $row = DB::selectOne(
                'SELECT c.convalidated, c.confupdtype
                   FROM pg_constraint c
                  WHERE c.contype = \'f\'
                    AND c.conname = ?
                    AND c.conrelid = ?::regclass',
                [$relationship['constraint'], $relationship['child']]
            );

            $this->assertNotNull(
                $row,
                "{$relationship['constraint']} is missing on {$relationship['child']}."
            );

            $this->assertTrue(
                (bool) $row->convalidated,
                "{$relationship['constraint']} is still NOT VALID; promote it with "
                ."ALTER TABLE {$relationship['child']} VALIDATE CONSTRAINT {$relationship['constraint']};"
            );

            // 'a' = NO ACTION. A cascade would silently re-home every child row
            // across a tenant boundary on a single UPDATE.
            $this->assertSame(
                'a',
                (string) $row->confupdtype,
                "{$relationship['constraint']} must not use ON UPDATE CASCADE."
            );
        }
    }
}
