<?php

namespace Tests\Feature;

use App\Contracts\UserRepositoryInterface;
use App\Enums\ProvisioningChannel;
use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Classe;
use App\Models\QRInvite;
use App\Models\Stage;
use App\Models\User;
use App\Services\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

/**
 * Business rule: an account created through a trusted/internal flow is
 * verified by default; an untrusted public submission is not.
 *
 * The rule is implemented once, in UserProvisioningService, keyed by
 * ProvisioningChannel. These tests pin the behaviour of every real flow that
 * creates a `users` row, plus the structural guarantees that keep the flows
 * honest.
 */
class EmailVerificationOnProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Provision@1234';

    /** @return array{0: Church, 1: Stage, 2: Classe} */
    private function tenant(): array
    {
        $church = Church::factory()->create();
        $stage = Stage::factory()->forChurch($church)->create();
        $classe = Classe::factory()->forChurch($church)->state(['stage_id' => $stage->id])->create();

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

    private function adminToken(User $admin): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])->assertStatus(200)->json('data.token');

        $this->assertIsString($token);

        return $token;
    }

    private function issueInvite(User $creator, QRInviteType $type, ?int $classId = null): string
    {
        $token = $this->withHeader('Authorization', 'Bearer '.$this->adminToken($creator))
            ->postJson('/api/v1/qr/invites', [
                'type' => $type->value,
                'class_id' => $classId,
            ])->assertStatus(201)->json('data.url');

        $this->assertIsString($token);

        return basename($token);
    }

    // =================================================================
    // Test 1 — Admin-created user
    // =================================================================

    public function test_admin_created_user_is_verified_and_can_log_in(): void
    {
        [$church] = $this->tenant();
        $admin = $this->admin($church);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken($admin))
            ->postJson('/api/v1/users', [
                'name' => 'Admin Created Member',
                'email' => 'admin-created@test.com',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => UserRole::Member->value,
                'is_active' => true,
            ]);

        $response->assertStatus(201);

        $created = User::where('email', 'admin-created@test.com')->first();

        // Assert user exists.
        $this->assertNotNull($created);
        // Assert email_verified_at is NOT NULL.
        $this->assertNotNull($created->email_verified_at, 'Admin-created users are verified by default.');
        // Assert login succeeds without any email verification step.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-created@test.com',
            'password' => self::PASSWORD,
        ])->assertStatus(200)
            ->assertJsonPath('data.user.role', 'member')
            ->assertJsonPath('data.user.id', $created->id);

        // Tenant + provenance are still server-derived.
        $this->assertSame($church->id, $created->church_id);
        $this->assertSame($admin->id, $created->created_by);
        $this->assertSame('approved', $created->application_status);
    }

    public function test_admin_created_servant_is_verified_and_can_log_in(): void
    {
        [$church, $stage, $classe] = $this->tenant();
        $admin = $this->admin($church);

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken($admin))
            ->postJson('/api/v1/users', [
                'name' => 'Admin Created Servant',
                'email' => 'admin-created-servant@test.com',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => UserRole::Servant->value,
                'class_id' => $classe->id,
                'stage_id' => $stage->id,
                'is_active' => true,
            ])->assertStatus(201);

        $created = User::where('email', 'admin-created-servant@test.com')->firstOrFail();
        $this->assertNotNull($created->email_verified_at);
        $this->assertSame(UserRole::Servant, $created->role);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-created-servant@test.com',
            'password' => self::PASSWORD,
        ])->assertStatus(200)->assertJsonPath('data.user.role', 'servant');
    }

    // =================================================================
    // Test 2 — QR invite -> Servant
    // =================================================================

    public function test_qr_invite_servant_is_verified_and_can_log_in_immediately(): void
    {
        [$church, $stage, $classe] = $this->tenant();
        $admin = $this->admin($church);

        $inviteToken = $this->issueInvite($admin, QRInviteType::AdminToServantInvite, $classe->id);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Invite Servant',
            'email' => 'invite-servant@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $inviteToken,
            'class_id' => $classe->id,
        ])->assertStatus(201)->assertJsonPath('data.user.role', 'servant');

        $created = User::where('email', 'invite-servant@test.com')->firstOrFail();

        $this->assertNotNull($created->email_verified_at, 'QR-invited servants are verified at acceptance.');
        $this->assertNull($created->email_verification_token, 'No separate verification step is required.');
        $this->assertNull($created->email_verification_token_expires_at);
        $this->assertSame($church->id, $created->church_id);
        $this->assertSame($stage->id, $created->stage_id);
        $this->assertSame(UserRole::Servant, $created->role);

        // Login succeeds immediately, with no verification round-trip.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'invite-servant@test.com',
            'password' => self::PASSWORD,
        ])->assertStatus(200)
            ->assertJsonPath('data.user.role', 'servant')
            ->assertJsonPath('data.access_state', 'full');
    }

    // =================================================================
    // Test 3 — QR invite -> Member
    // =================================================================

    public function test_qr_invite_member_is_verified_and_can_log_in_immediately(): void
    {
        [$church, , $classe] = $this->tenant();
        $admin = $this->admin($church);

        $inviteToken = $this->issueInvite($admin, QRInviteType::ServantToMemberInvite, $classe->id);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Invite Member',
            'email' => 'invite-member@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $inviteToken,
            'class_id' => $classe->id,
        ])->assertStatus(201)->assertJsonPath('data.user.role', 'member');

        $created = User::where('email', 'invite-member@test.com')->firstOrFail();

        $this->assertNotNull($created->email_verified_at, 'QR-invited members are verified at acceptance.');
        $this->assertNull($created->email_verification_token);
        $this->assertNull($created->email_verification_token_expires_at);
        $this->assertSame(UserRole::Member, $created->role);
        $this->assertSame($church->id, $created->church_id);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'invite-member@test.com',
            'password' => self::PASSWORD,
        ])->assertStatus(200)->assertJsonPath('data.user.role', 'member');
    }

    // =================================================================
    // Security regression — the verification rule must not weaken invites
    // =================================================================

    public function test_expired_invite_still_cannot_create_a_verified_account(): void
    {
        [$church, , $classe] = $this->tenant();
        $admin = $this->admin($church);

        $invite = QRInvite::create([
            'type' => QRInviteType::AdminToServantInvite,
            'token' => str_repeat('e', 64),
            'created_by' => $admin->id,
            'church_id' => $church->id,
            'class_id' => $classe->id,
            'expires_at' => now()->subHour(),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Expired',
            'email' => 'expired-invite@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $invite->token,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'expired-invite@test.com']);
    }

    public function test_used_invite_still_cannot_create_a_second_verified_account(): void
    {
        [$church, , $classe] = $this->tenant();
        $admin = $this->admin($church);

        $inviteToken = $this->issueInvite($admin, QRInviteType::ServantToMemberInvite, $classe->id);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'First',
            'email' => 'first-use@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $inviteToken,
        ])->assertStatus(201);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Replay',
            'email' => 'replay@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $inviteToken,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'replay@test.com']);
    }

    public function test_invalid_invite_token_cannot_create_a_verified_account(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'No Invite',
            'email' => 'no-invite@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => str_repeat('n', 64),
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'no-invite@test.com']);
    }

    public function test_attendance_qr_cannot_be_used_to_register_an_account(): void
    {
        // attendance_qr has no targetRole(), so it can never be exchanged for an
        // account — the "wrong-role invite" case.
        [$church] = $this->tenant();
        $admin = $this->admin($church);

        $inviteToken = $this->issueInvite($admin, QRInviteType::AttendanceQR);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wrong Role',
            'email' => 'wrong-role@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $inviteToken,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'wrong-role@test.com']);
    }

    public function test_cross_tenant_invite_cannot_create_a_verified_account(): void
    {
        [$churchA, , $classeA] = $this->tenant();
        $churchB = Church::factory()->create();
        $classB = Classe::factory()->forChurch($churchB)->create();
        $adminA = $this->admin($churchA);

        $inviteToken = $this->issueInvite($adminA, QRInviteType::AdminToServantInvite, $classeA->id);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Cross Tenant',
            'email' => 'cross-tenant@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $inviteToken,
            'class_id' => $classB->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'cross-tenant@test.com']);
    }

    public function test_unauthorized_actor_cannot_create_a_verified_user(): void
    {
        [$church, , $classe] = $this->tenant();
        $admin = $this->admin($church);
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'class_id' => $classe->id,
            'stage_id' => $classe->stage_id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        $servantToken = $this->postJson('/api/v1/auth/login', [
            'email' => $servant->email,
            'password' => self::PASSWORD,
        ])->assertStatus(200)->json('data.token');

        // A servant has no manage_users permission.
        $this->withHeader('Authorization', 'Bearer '.$servantToken)
            ->postJson('/api/v1/users', [
                'name' => 'Forged',
                'email' => 'forged@test.com',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => UserRole::Member->value,
            ])->assertStatus(403);

        // Anonymous callers cannot reach the admin creation flow at all.
        $anonymous = $this->postJson('/api/v1/users', [
            'name' => 'Forged Anonymous',
            'email' => 'forged-anon@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => UserRole::Member->value,
        ]);

        $this->assertContains(
            $anonymous->status(),
            [401, 403],
            'An anonymous caller must be refused by the protected creation route.'
        );
        $this->assertNotSame(201, $anonymous->status());
        $this->assertNotNull($anonymous->content());

        $this->assertDatabaseMissing('users', ['email' => 'forged@test.com']);
        $this->assertDatabaseMissing('users', ['email' => 'forged-anon@test.com']);
        $this->assertNotNull($admin->id);
    }

    // =================================================================
    // Untrusted public registration is NOT auto-verified
    // =================================================================

    public function test_untrusted_church_application_submission_stays_unverified(): void
    {
        $this->post('/api/v1/church-applications', [
            'church_name' => 'Untrusted Church',
            'priest_name' => 'Untrusted Priest',
            'address' => '1 Anonymous Way',
            'phone' => '01234567890',
            'main_servant_name' => 'Untrusted Servant',
            'id_type' => 'national_id',
            'front_id' => UploadedFile::fake()->create('front.jpg', 64, 'image/jpeg'),
            'back_id' => UploadedFile::fake()->create('back.jpg', 64, 'image/jpeg'),
            'email' => 'untrusted-applicant@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $applicant = User::where('email', 'untrusted-applicant@test.com')->firstOrFail();

        // A self-declared address is never auto-verified.
        $this->assertNull($applicant->email_verified_at);
        $this->assertSame('pending', $applicant->application_status);

        // ...and therefore cannot authenticate.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'untrusted-applicant@test.com',
            'password' => self::PASSWORD,
        ])->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    }

    // =================================================================
    // Structural guarantees of the single enforcement point
    // =================================================================

    public function test_provisioning_service_is_the_only_insert_path(): void
    {
        $church = Church::factory()->create();

        // The repository refuses a payload with no explicit verification decision.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires an explicit email_verified_at decision');

        app(UserRepositoryInterface::class)->create([
            'name' => 'No Decision',
            'email' => 'no-decision@test.com',
            'password' => Hash::make(self::PASSWORD),
            'role' => UserRole::Member->value,
            'church_id' => $church->id,
        ]);
    }

    public function test_invite_backed_channels_require_an_invite_id(): void
    {
        $church = Church::factory()->create();
        $service = app(UserProvisioningService::class);

        foreach ([ProvisioningChannel::QrInviteServant, ProvisioningChannel::QrInviteMember] as $channel) {
            try {
                $service->create([
                    'name' => 'No Invite Id',
                    'email' => 'no-invite-id-'.$channel->value.'@test.com',
                    'password' => Hash::make(self::PASSWORD),
                    'role' => $channel === ProvisioningChannel::QrInviteServant
                        ? UserRole::Servant->value
                        : UserRole::Member->value,
                    'church_id' => $church->id,
                ], $channel);
                $this->fail("Channel {$channel->value} must not be usable without a validated invite_id.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('requires a validated invite_id', $e->getMessage());
            }
        }

        $this->assertDatabaseMissing('users', ['email' => 'no-invite-id-qr_invite_servant@test.com']);
        $this->assertDatabaseMissing('users', ['email' => 'no-invite-id-qr_invite_member@test.com']);
    }

    public function test_channel_trust_matrix(): void
    {
        $trusted = [
            ProvisioningChannel::AdminCreated,
            ProvisioningChannel::QrInviteServant,
            ProvisioningChannel::QrInviteMember,
            ProvisioningChannel::ChurchApplicationApproved,
            ProvisioningChannel::MembershipRequestApproved,
            ProvisioningChannel::PlatformBootstrap,
        ];

        foreach ($trusted as $channel) {
            $this->assertTrue(
                $channel->establishesEmailOwnership(),
                "{$channel->value} must establish email ownership."
            );
        }

        $this->assertFalse(ProvisioningChannel::ChurchApplicationSubmitted->establishesEmailOwnership());
    }

    public function test_every_trusted_channel_verifies_and_untrusted_does_not(): void
    {
        $service = app(UserProvisioningService::class);
        $church = Church::factory()->create();
        $inviteAuthor = $this->admin($church);
        $invite = QRInvite::create([
            'type' => QRInviteType::AdminToServantInvite,
            'token' => str_repeat('p', 64),
            'created_by' => $inviteAuthor->id,
            'church_id' => $church->id,
            'expires_at' => now()->addHour(),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $index = 0;
        foreach (ProvisioningChannel::cases() as $channel) {
            $attributes = [
                'name' => 'Channel Probe',
                'email' => 'channel-'.$channel->value.'-'.$index++.'@test.com',
                'password' => Hash::make(self::PASSWORD),
                'role' => UserRole::Member->value,
                'church_id' => $church->id,
            ];
            if ($channel->requiresInvite()) {
                $attributes['invite_id'] = $invite->id;
            }

            $user = $service->create($attributes, $channel);

            if ($channel->establishesEmailOwnership()) {
                $this->assertNotNull($user->fresh()?->email_verified_at, "{$channel->value} must verify.");
            } else {
                $this->assertNull($user->fresh()?->email_verified_at, "{$channel->value} must NOT verify.");
            }
        }
    }

    // =================================================================
    // Existing accounts are not retroactively changed
    // =================================================================

    public function test_provisioning_does_not_reverify_existing_accounts(): void
    {
        $church = Church::factory()->create();
        $legacy = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $church->id,
            'email_verified_at' => null,
        ]);

        $service = app(UserProvisioningService::class);

        $service->create([
            'name' => 'New One',
            'email' => 'new-verified@test.com',
            'password' => Hash::make(self::PASSWORD),
            'role' => UserRole::Member->value,
            'church_id' => $church->id,
        ], ProvisioningChannel::AdminCreated);

        $this->assertNull($legacy->fresh()?->email_verified_at, 'Existing rows are untouched by this rule.');
        $this->assertNotNull(User::where('email', 'new-verified@test.com')->firstOrFail()->email_verified_at);
    }
}
