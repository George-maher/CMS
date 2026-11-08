<?php

namespace Tests\Feature;

use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Permission;
use App\Models\QRInvite;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-11 — the invite `search` filter must produce valid SQL on every driver.
 *
 * QRInviteRepository::applySearch() used the PostgreSQL-only `ilike` operator
 * unconditionally for the creator/used-by name and email columns. SQLite — the
 * driver used by the test suite and by local development — has no `ilike`
 * translation, so any list request carrying a `search` parameter failed with a
 * malformed-query error instead of returning results. The operator is now
 * selected per driver: `ilike` on PostgreSQL, `like` elsewhere.
 */
class QRInviteSearchTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private User $admin;

    private QRInvite $invite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Permission::clearCache();

        $this->church = Church::factory()->create();

        // The searching account is also the invite creator, so a search on its
        // name/email exercises the `creator` branch of applySearch().
        $this->admin = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane.doe@example.org',
            'role' => UserRole::Admin,
            'church_id' => $this->church->id,
            'application_status' => 'approved',
            'is_active' => true,
        ]);

        $this->invite = QRInvite::create([
            'type' => QRInviteType::AdminToServantInvite->value,
            'token' => 'searchable-token-'.Str::random(40),
            'created_by' => $this->admin->id,
            'church_id' => $this->church->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);
    }

    private function search(string $term): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/v1/qr/invites?search='.urlencode($term));
    }

    /**
     * @return array<int, int>
     */
    private function ids(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<int, array{id: int}> $data */
        $data = $response->json('data');

        return array_values(array_map(static fn (array $row): int => $row['id'], $data));
    }

    public function test_search_by_creator_name_is_case_insensitive(): void
    {
        // Before the fix, this request returned invalid SQL on SQLite.
        $this->assertEqualsCanonicalizing([$this->invite->id], $this->ids($this->search('jane')));
        $this->assertEqualsCanonicalizing([$this->invite->id], $this->ids($this->search('JANE')));
    }

    public function test_search_by_creator_email_is_case_insensitive(): void
    {
        $this->assertEqualsCanonicalizing([$this->invite->id], $this->ids($this->search('JANE.DOE')));
    }

    public function test_search_by_token_fragment_finds_the_invite(): void
    {
        $this->assertEqualsCanonicalizing([$this->invite->id], $this->ids($this->search('searchable-token-')));
    }

    public function test_search_returns_no_rows_for_an_unknown_term(): void
    {
        $this->assertSame([], $this->ids($this->search('no-such-creator-anywhere')));
    }

    public function test_search_never_returns_another_churchs_invites(): void
    {
        $otherChurch = Church::factory()->create();
        $otherAdmin = User::factory()->create([
            'name' => 'Jane Other',
            'email' => 'other.jane@example.org',
            'role' => UserRole::Admin,
            'church_id' => $otherChurch->id,
            'application_status' => 'approved',
        ]);

        $foreignInvite = QRInvite::create([
            'type' => QRInviteType::AdminToServantInvite->value,
            'token' => 'foreign-token-'.Str::random(40),
            'created_by' => $otherAdmin->id,
            'church_id' => $otherChurch->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $ids = $this->ids($this->search('Jane'));

        $this->assertContains($this->invite->id, $ids);
        $this->assertNotContains($foreignInvite->id, $ids);
    }
}
