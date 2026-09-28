<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Classe;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Defence in depth for the structural chain
 *
 *   Church -> Stage -> Class -> User
 *
 * The application already refuses a cross-tenant reference; these tests pin that
 * behaviour at the HTTP boundary so a future change to a FormRequest, a
 * `exists:` rule or a controller cannot silently reintroduce a row that links a
 * user to another church's stage or class.
 */
class TenantHierarchyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Integrity@1234';

    /** @return array{0: Church, 1: Stage, 2: Classe} */
    private function tenant(string $suffix = 'A'): array
    {
        $church = Church::factory()->create(['name' => 'Church '.$suffix]);
        $stage = Stage::factory()->forChurch($church)->create(['name' => 'Stage '.$suffix]);
        $classe = Classe::factory()->forChurch($church)->state(['stage_id' => $stage->id])->create(['name' => 'Class '.$suffix]);

        return [$church, $stage, $classe];
    }

    private function admin(Church $church): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    private function token(User $user): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(200)->json('data.token');

        $this->assertIsString($token);

        return $token;
    }

    /** A church admin cannot create a class inside another church's stage. */
    public function test_church_admin_cannot_create_class_in_another_churchs_stage(): void
    {
        [$churchA] = $this->tenant('A');
        [, , $classB] = $this->tenant('B');
        $adminA = $this->admin($churchA);

        $this->withHeader('Authorization', 'Bearer '.$this->token($adminA))
            ->postJson('/api/v1/classes', [
                'stage_id' => $classB->stage_id,
                'name' => 'Injected Class',
            ])->assertStatus(404);

        $this->assertDatabaseMissing('classes', ['name' => 'Injected Class']);
    }

    /** A church admin cannot bind a new user to another church's class. */
    public function test_church_admin_cannot_create_member_bound_to_another_churchs_class(): void
    {
        [$churchA] = $this->tenant('A');
        [, , $classB] = $this->tenant('B');
        $adminA = $this->admin($churchA);

        $this->withHeader('Authorization', 'Bearer '.$this->token($adminA))
            ->postJson('/api/v1/users', [
                'name' => 'Injected Member',
                'email' => 'injected-member@test.com',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => UserRole::Member->value,
                'class_id' => $classB->id,
            ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'injected-member@test.com']);
    }

    /** A church admin cannot create a stage admin bound to another church's stage. */
    public function test_church_admin_cannot_create_stage_admin_bound_to_another_churchs_stage(): void
    {
        [$churchA] = $this->tenant('A');
        [, $stageB] = $this->tenant('B');
        $adminA = $this->admin($churchA);

        $this->withHeader('Authorization', 'Bearer '.$this->token($adminA))
            ->postJson('/api/v1/users', [
                'name' => 'Injected Stage Admin',
                'email' => 'injected-stage-admin@test.com',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => UserRole::StageAdmin->value,
                'stage_id' => $stageB->id,
            ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'injected-stage-admin@test.com']);
    }

    /** The legitimate equivalent still works, so the rules above are not a blanket block. */
    public function test_church_admin_still_creates_class_and_users_within_own_church(): void
    {
        [$churchA, $stageA] = $this->tenant('A');
        $adminA = $this->admin($churchA);

        $this->withHeader('Authorization', 'Bearer '.$this->token($adminA))
            ->postJson('/api/v1/classes', [
                'stage_id' => $stageA->id,
                'name' => 'Own Class',
            ])->assertStatus(201);

        $ownClass = Classe::query()->where('name', 'Own Class')->firstOrFail();
        $this->assertSame($churchA->id, $ownClass->church_id);
        $this->assertSame($stageA->id, $ownClass->stage_id);

        $this->withHeader('Authorization', 'Bearer '.$this->token($adminA))
            ->postJson('/api/v1/users', [
                'name' => 'Own Member',
                'email' => 'own-member@test.com',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => UserRole::Member->value,
                'class_id' => $ownClass->id,
            ])->assertStatus(201);

        $member = User::where('email', 'own-member@test.com')->firstOrFail();
        $this->assertSame($churchA->id, $member->church_id);
        $this->assertSame($ownClass->id, $member->class_id);
    }

    /** A stage admin cannot bind a user to a class in a different stage. */
    public function test_stage_admin_cannot_bind_a_user_to_a_class_in_another_stage(): void
    {
        [$churchA, $stageA] = $this->tenant('A');
        [, , $classB] = $this->tenant('B');
        [, $stageB] = $this->tenant('B');

        // Make the B tenant live inside church A so only the *stage* differs.
        $stageB->update(['church_id' => $churchA->id]);
        $classB->update(['church_id' => $churchA->id, 'stage_id' => $stageB->id]);

        $stageAdmin = User::factory()->create([
            'role' => UserRole::StageAdmin,
            'church_id' => $churchA->id,
            'stage_id' => $stageA->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        Sanctum::actingAs($stageAdmin);

        $this->postJson('/api/v1/users', [
            'name' => 'Stage Crossing Member',
            'email' => 'stage-crossing@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => UserRole::Member->value,
            'class_id' => $classB->id,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'stage-crossing@test.com']);
    }
}
