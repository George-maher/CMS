<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Classe;
use App\Models\DailySpiritualRecord;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailySpiritualRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Permission::clearCache();
    }

    private function createMemberWithChurch(): array
    {
        $classe = Classe::factory()->create();
        $churchId = $classe->church_id;

        $member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchId,
            'class_id' => $classe->id,
        ]);

        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $churchId,
            'class_id' => $classe->id,
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $churchId,
        ]);

        return compact('member', 'servant', 'admin', 'classe', 'churchId');
    }

    public function test_member_can_create_today_record(): void
    {
        ['member' => $member] = $this->createMemberWithChurch();
        $token = $member->createToken('test', [$member->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => now()->toDateString(),
                'attended_mass' => true,
                'confessed' => false,
                'received_communion' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.attended_mass', true)
            ->assertJsonPath('data.confessed', false)
            ->assertJsonPath('data.received_communion', true)
            ->assertJsonPath('data.activity_date', now()->toDateString());
    }

    public function test_member_can_edit_own_record(): void
    {
        ['member' => $member] = $this->createMemberWithChurch();
        $token = $member->createToken('test', [$member->role->value])->plainTextToken;
        $date = now()->toDateString();

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => $date,
                'attended_mass' => true,
                'confessed' => false,
                'received_communion' => false,
            ])->assertStatus(201);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => $date,
                'attended_mass' => true,
                'confessed' => true,
                'received_communion' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.attended_mass', true)
            ->assertJsonPath('data.confessed', true)
            ->assertJsonPath('data.received_communion', true);

        $this->assertDatabaseCount('daily_spiritual_records', 1);
    }

    public function test_member_can_view_own_records(): void
    {
        ['member' => $member] = $this->createMemberWithChurch();
        $token = $member->createToken('test', [$member->role->value])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => '2026-09-01',
                'attended_mass' => true,
            ])->assertStatus(201);

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => '2026-09-02',
                'confessed' => true,
            ])->assertStatus(201);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/spiritual-records');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_member_cannot_access_other_member_records(): void
    {
        ['member' => $member, 'churchId' => $churchId, 'classe' => $classe] = $this->createMemberWithChurch();
        $otherMember = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchId,
            'class_id' => $classe->id,
        ]);

        $token = $member->createToken('test', [$member->role->value])->plainTextToken;

        // Create record for other member
        DailySpiritualRecord::create([
            'user_id' => $otherMember->id,
            'church_id' => $churchId,
            'activity_date' => '2026-09-01',
            'attended_mass' => true,
        ]);

        // Member cannot see other member's records
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/spiritual-records?member_id='.$otherMember->id);

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_separate_dates_create_separate_records(): void
    {
        ['member' => $member] = $this->createMemberWithChurch();
        $token = $member->createToken('test', [$member->role->value])->plainTextToken;

        $date1 = now()->subDays(2)->toDateString();
        $date2 = now()->subDay()->toDateString();

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => $date1,
                'attended_mass' => true,
                'confessed' => false,
                'received_communion' => false,
            ])->assertStatus(201);

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => $date2,
                'attended_mass' => false,
                'confessed' => true,
                'received_communion' => true,
            ])->assertStatus(201);

        $this->assertDatabaseCount('daily_spiritual_records', 2);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/spiritual-records');

        $records = $response->json('data');
        $this->assertCount(2, $records);

        $record1 = collect($records)->firstWhere('activity_date', $date1);
        $this->assertTrue($record1['attended_mass']);
        $this->assertFalse($record1['confessed']);
        $this->assertFalse($record1['received_communion']);

        $record2 = collect($records)->firstWhere('activity_date', $date2);
        $this->assertFalse($record2['attended_mass']);
        $this->assertTrue($record2['confessed']);
        $this->assertTrue($record2['received_communion']);
    }

    public function test_all_activity_combinations_work(): void
    {
        ['member' => $member] = $this->createMemberWithChurch();
        $token = $member->createToken('test', [$member->role->value])->plainTextToken;

        $combinations = [
            ['attended_mass' => true, 'confessed' => false, 'received_communion' => false],
            ['attended_mass' => false, 'confessed' => true, 'received_communion' => false],
            ['attended_mass' => false, 'confessed' => false, 'received_communion' => true],
            ['attended_mass' => true, 'confessed' => true, 'received_communion' => true],
        ];

        foreach ($combinations as $i => $combo) {
            $date = now()->subDays(10 - $i)->toDateString();
            $response = $this->withHeader('Authorization', "Bearer $token")
                ->postJson('/api/v1/spiritual-records', array_merge(
                    ['activity_date' => $date],
                    $combo,
                ));

            $response->assertStatus(201);
            $this->assertDatabaseHas('daily_spiritual_records', array_merge(
                ['user_id' => $member->id, 'activity_date' => $date],
                $combo,
            ));
        }
    }

    public function test_invalid_date_rejected(): void
    {
        ['member' => $member] = $this->createMemberWithChurch();
        $token = $member->createToken('test', [$member->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => 'not-a-date',
                'attended_mass' => true,
            ]);

        $response->assertStatus(422);
    }

    public function test_future_date_rejected(): void
    {
        ['member' => $member] = $this->createMemberWithChurch();
        $token = $member->createToken('test', [$member->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => now()->addDays(5)->toDateString(),
                'attended_mass' => true,
            ]);

        $response->assertStatus(422);
    }

    public function test_unauthorized_request_rejected(): void
    {
        $response = $this->postJson('/api/v1/spiritual-records', [
            'activity_date' => now()->toDateString(),
            'attended_mass' => true,
        ]);

        $response->assertStatus(401);
    }

    public function test_servant_can_view_assigned_member_records(): void
    {
        ['member' => $member, 'servant' => $servant, 'churchId' => $churchId] = $this->createMemberWithChurch();

        $recordDate = now()->subDay()->toDateString();

        DailySpiritualRecord::create([
            'user_id' => $member->id,
            'church_id' => $churchId,
            'activity_date' => $recordDate,
            'attended_mass' => true,
        ]);

        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/spiritual-records?member_id='.$member->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_servant_cannot_view_unrelated_member_records(): void
    {
        ['servant' => $servant, 'churchId' => $churchId] = $this->createMemberWithChurch();
        $otherClasse = Classe::factory()->create(['church_id' => $churchId]);
        $unrelatedMember = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchId,
            'class_id' => $otherClasse->id,
        ]);

        $token = $servant->createToken('test', [$servant->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/spiritual-records?member_id='.$unrelatedMember->id);

        $response->assertStatus(403);
    }

    public function test_admin_can_view_any_member_records(): void
    {
        ['member' => $member, 'admin' => $admin, 'churchId' => $churchId] = $this->createMemberWithChurch();

        DailySpiritualRecord::create([
            'user_id' => $member->id,
            'church_id' => $churchId,
            'activity_date' => now()->subDay()->toDateString(),
            'attended_mass' => true,
        ]);

        $token = $admin->createToken('test', [$admin->role->value])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/spiritual-records?member_id='.$member->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_member_can_delete_own_record(): void
    {
        ['member' => $member] = $this->createMemberWithChurch();
        $token = $member->createToken('test', [$member->role->value])->plainTextToken;
        $date = now()->subDay()->toDateString();

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => $date,
                'attended_mass' => true,
            ])->assertStatus(201);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/spiritual-records/{$date}");

        $response->assertOk();
        $this->assertDatabaseCount('daily_spiritual_records', 0);
    }

    public function test_date_range_filtering_works(): void
    {
        ['member' => $member] = $this->createMemberWithChurch();
        $token = $member->createToken('test', [$member->role->value])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => now()->subDays(30)->toDateString(),
                'attended_mass' => true,
            ])->assertStatus(201);

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => now()->subDays(5)->toDateString(),
                'attended_mass' => true,
            ])->assertStatus(201);

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/spiritual-records', [
                'activity_date' => now()->subDays(2)->toDateString(),
                'attended_mass' => true,
            ])->assertStatus(201);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/spiritual-records?date_from='.now()->subDays(10)->toDateString().'&date_to='.now()->toDateString());

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
