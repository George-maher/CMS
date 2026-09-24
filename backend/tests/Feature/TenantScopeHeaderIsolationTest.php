<?php

namespace Tests\Feature;

use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Throwable;

class TenantScopeHeaderIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_church_header_cannot_select_records_for_a_web_query(): void
    {
        $stage = Stage::factory()->create();

        Route::get('/__test/tenant-scope/stages', function () {
            return response()->json(['ids' => Stage::query()->pluck('id')->values()->all()]);
        });

        $this->withHeader('X-Church-ID', (string) $stage->church_id)
            ->getJson('/__test/tenant-scope/stages')
            ->assertOk()
            ->assertJsonPath('ids', []);
    }

    public function test_unauthenticated_church_header_cannot_assign_model_tenant(): void
    {
        Route::post('/__test/tenant-scope/stages', function () {
            try {
                $stage = Stage::create([
                    'name' => 'Header Injection Stage',
                    'display_order' => 1,
                ]);

                return response()->json(['church_id' => $stage->church_id]);
            } catch (Throwable) {
                return response()->json(['church_id' => null], 422);
            }
        });

        $this->withHeader('X-Church-ID', '999999')
            ->postJson('/__test/tenant-scope/stages')
            ->assertStatus(422)
            ->assertJsonPath('church_id', null);
    }
}
