<?php

namespace Tests\Feature;

use App\Enums\LoginFailureCode;
use App\Enums\QRInviteType;
use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Classe;
use App\Models\QRInvite;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Covers the full Church Admin -> QR invitation -> Servant login lifecycle,
 * plus every branch of the typed authentication-failure contract.
 *
 * @see LoginFailureCode
 */
class ServantLoginLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Servant@1234';

    private function churchWithStageAndClass(): array
    {
        $church = Church::factory()->create();
        $stage = Stage::factory()->forChurch($church)->create();
        $classe = Classe::factory()->forChurch($church)->state(['stage_id' => $stage->id])->create();

        return [$church, $stage, $classe];
    }

    private function churchAdmin(Church $church): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    private function login(User $user, string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ]);
    }

    // -----------------------------------------------------------------
    // 1. Church Admin login
    // -----------------------------------------------------------------

    public function test_church_admin_can_login(): void
    {
        $church = Church::factory()->create();
        $admin = $this->churchAdmin($church);

        $this->login($admin)
            ->assertStatus(200)
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.access_state', 'full');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $admin->id,
            'tokenable_type' => User::class,
        ]);
    }

    // -----------------------------------------------------------------
    // 6/7/8. QR invitation creation + acceptance
    // -----------------------------------------------------------------

    public function test_admin_creates_invite_and_servant_accepts_it(): void
    {
        [$church, $stage, $classe] = $this->churchWithStageAndClass();
        $admin = $this->churchAdmin($church);

        $token = $this->login($admin)->json('data.token');
        $this->assertNotNull($token);

        // 6. QR invitation creation
        $inviteResponse = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::AdminToServantInvite->value,
                'class_id' => $classe->id,
            ]);
        $inviteResponse->assertStatus(201);
        $inviteToken = basename((string) $inviteResponse->json('data.url'));

        // The invite must be tenant-bound and created by the admin.
        $invite = QRInvite::where('token', $inviteToken)->firstOrFail();
        $this->assertSame($church->id, $invite->church_id);
        $this->assertSame($admin->id, $invite->created_by);
        $this->assertSame(UserRole::Servant, $invite->type->targetRole());

        // 7. Invitation acceptance
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Accepted Servant',
            'email' => 'accepted-servant@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $inviteToken,
            'class_id' => $classe->id,
        ]);
        $registerResponse->assertStatus(201)
            ->assertJsonPath('data.user.role', 'servant');

        $servant = User::where('email', 'accepted-servant@test.com')->firstOrFail();

        // Tenant + provenance are derived server-side, never from the client.
        $this->assertSame($church->id, $servant->church_id);
        $this->assertSame($stage->id, $servant->stage_id);
        $this->assertSame($classe->id, $servant->class_id);
        $this->assertSame($admin->id, $servant->created_by);
        $this->assertSame($invite->id, $servant->invite_id);
        $this->assertTrue($servant->is_active);
        $this->assertSame('approved', $servant->application_status);

        // The invite is single-use and now consumed by this user.
        $invite->refresh();
        $this->assertSame(1, $invite->use_count);
        $this->assertSame($servant->id, $invite->used_by);
        $this->assertNotNull($invite->used_at);
    }

    public function test_invitation_acceptance_establishes_the_verified_email_state(): void
    {
        [$church, , $classe] = $this->churchWithStageAndClass();
        $admin = $this->churchAdmin($church);
        $token = $this->login($admin)->json('data.token');

        $inviteToken = basename((string) $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/qr/invites', [
                'type' => QRInviteType::AdminToServantInvite->value,
                'class_id' => $classe->id,
            ])->json('data.url'));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Verified By Invite',
            'email' => 'verified-by-invite@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $inviteToken,
        ])->assertStatus(201);

        $servant = User::where('email', 'verified-by-invite@test.com')->firstOrFail();

        $this->assertNotNull($servant->email_verified_at, 'Invitation acceptance is the email-ownership proof.');
        $this->assertNull($servant->email_verification_token);
        $this->assertNull($servant->email_verification_token_expires_at);
    }

    // -----------------------------------------------------------------
    // 2/17. Servant login + Sanctum token
    // -----------------------------------------------------------------

    public function test_servant_with_verified_email_can_login_and_issue_sanctum_token(): void
    {
        [$church] = $this->churchWithStageAndClass();

        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        $response = $this->login($servant)
            ->assertStatus(200)
            ->assertJsonPath('data.user.role', 'servant')
            ->assertJsonPath('data.user.email_verified_at', $servant->email_verified_at?->toISOString());

        // 17. Sanctum token creation
        $plainTextToken = $response->json('data.token');
        $this->assertIsString($plainTextToken);
        // Sanctum's plain text token is "<id>|<secret>".
        [$tokenId, $secret] = explode('|', $plainTextToken, 2);
        $this->assertNotEmpty($secret);

        $accessToken = PersonalAccessToken::find((int) $tokenId);
        $this->assertNotNull($accessToken);
        $this->assertSame($servant->id, $accessToken->tokenable_id);
        $this->assertSame(User::class, $accessToken->tokenable_type);
        $this->assertSame(hash('sha256', $secret), $accessToken->token);
        $this->assertContains('servant', $accessToken->abilities ?? []);

        // The token authenticates the very next request.
        $this->withHeader('Authorization', "Bearer $plainTextToken")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.user.role', 'servant')
            ->assertJsonPath('data.user.church_id', $church->id);
    }

    // -----------------------------------------------------------------
    // 3. Unverified servant is refused with the typed contract
    // -----------------------------------------------------------------

    public function test_servant_with_unverified_email_is_refused(): void
    {
        [$church] = $this->churchWithStageAndClass();

        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'application_status' => 'approved',
            'email_verified_at' => null,
            'password' => Hash::make(self::PASSWORD),
        ]);

        $response = $this->login($servant)
            ->assertStatus(LoginFailureCode::EmailNotVerified->status())
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', LoginFailureCode::EmailNotVerified->value)
            ->assertJsonPath('message', __('auth.email_not_verified'));

        // The gate must not leak a token.
        $this->assertNull($response->json('data.token'));
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $servant->id)->count());
    }

    // -----------------------------------------------------------------
    // 4. Invalid password
    // -----------------------------------------------------------------

    public function test_servant_with_wrong_password_gets_401_login_failed(): void
    {
        [$church] = $this->churchWithStageAndClass();
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->login($servant, 'WrongPassword@1')
            ->assertStatus(LoginFailureCode::InvalidCredentials->status())
            ->assertJsonPath('code', LoginFailureCode::InvalidCredentials->value);
    }

    public function test_unknown_email_gets_the_same_401_as_a_wrong_password(): void
    {
        // No account enumeration: identical status, code and message.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@test.com',
            'password' => 'Whatever@1234',
        ])
            ->assertStatus(LoginFailureCode::InvalidCredentials->status())
            ->assertJsonPath('code', LoginFailureCode::InvalidCredentials->value)
            ->assertJsonPath('message', __('auth.failed'));
    }

    // -----------------------------------------------------------------
    // 5. Inactive servant
    // -----------------------------------------------------------------

    public function test_inactive_servant_is_refused(): void
    {
        [$church] = $this->churchWithStageAndClass();
        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'is_active' => false,
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->login($servant)
            ->assertStatus(LoginFailureCode::AccountInactive->status())
            ->assertJsonPath('code', LoginFailureCode::AccountInactive->value)
            ->assertJsonPath('message', __('auth.inactive'));
    }

    // -----------------------------------------------------------------
    // 9/10. Email verification endpoint semantics
    // -----------------------------------------------------------------

    public function test_verify_email_with_valid_token_succeeds(): void
    {
        $rawToken = str_repeat('v', 64);
        $user = User::factory()->create([
            'email' => 'verify-ok@test.com',
            'email_verified_at' => null,
            'email_verification_token' => Hash::make($rawToken),
            'email_verification_token_expires_at' => now()->addHours(12),
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'verify-ok@test.com',
            'token' => $rawToken,
        ])->assertStatus(200);

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_token);
        $this->assertNull($user->email_verification_token_expires_at);
    }

    public function test_verify_email_with_expired_token_is_rejected(): void
    {
        $rawToken = str_repeat('e', 64);
        $user = User::factory()->create([
            'email' => 'verify-expired-2@test.com',
            'email_verified_at' => null,
            'email_verification_token' => Hash::make($rawToken),
            'email_verification_token_expires_at' => now()->subMinute(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'verify-expired-2@test.com',
            'token' => $rawToken,
        ])->assertStatus(400);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verify_email_on_an_already_verified_account_is_idempotent(): void
    {
        $user = User::factory()->create([
            'email' => 'verify-twice@test.com',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        // An already-verified account must stay verified, but the response is
        // the same indistinguishable failure as for an unknown address or a
        // wrong token. Answering 200 "Email is already verified" here would
        // confirm the address is registered and turn this public endpoint
        // into an account-enumeration oracle. See
        // EmailVerificationTokenSecurityTest for the pinned contract.
        $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'verify-twice@test.com',
            'token' => str_repeat('a', 64),
        ])->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VERIFICATION_FAILED');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verify_email_rejects_an_invalid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'verify-bad@test.com',
            'email_verified_at' => null,
            'email_verification_token' => Hash::make(str_repeat('a', 64)),
            'email_verification_token_expires_at' => now()->addHour(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->postJson('/api/v1/auth/verify-email', [
            'email' => 'verify-bad@test.com',
            'token' => str_repeat('b', 64),
        ])->assertStatus(400);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    // -----------------------------------------------------------------
    // 11/12. QR invitation expiry and reuse
    // -----------------------------------------------------------------

    public function test_expired_qr_invitation_cannot_register_an_account(): void
    {
        [$church, , $classe] = $this->churchWithStageAndClass();
        $admin = $this->churchAdmin($church);

        $invite = QRInvite::create([
            'type' => QRInviteType::AdminToServantInvite,
            'token' => str_repeat('x', 64),
            'created_by' => $admin->id,
            'church_id' => $church->id,
            'class_id' => $classe->id,
            'expires_at' => now()->subHour(),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Too Late',
            'email' => 'too-late@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $invite->token,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'too-late@test.com']);
    }

    public function test_qr_invitation_cannot_be_reused(): void
    {
        [$church, , $classe] = $this->churchWithStageAndClass();
        $admin = $this->churchAdmin($church);

        $invite = QRInvite::create([
            'type' => QRInviteType::AdminToServantInvite,
            'token' => str_repeat('y', 64),
            'created_by' => $admin->id,
            'church_id' => $church->id,
            'class_id' => $classe->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'First Accept',
            'email' => 'first-accept@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $invite->token,
        ])->assertStatus(201);

        // Second use of the same token must fail and create nothing.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Second Accept',
            'email' => 'second-accept@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $invite->token,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'second-accept@test.com']);
        $this->assertSame(1, $invite->fresh()->use_count);
    }

    // -----------------------------------------------------------------
    // 13/10. Wrong-church invitation
    // -----------------------------------------------------------------

    public function test_invitation_cannot_attach_a_user_to_another_church(): void
    {
        [$churchA, , $classeA] = $this->churchWithStageAndClass();
        $churchB = Church::factory()->create();
        $adminA = $this->churchAdmin($churchA);

        $invite = QRInvite::create([
            'type' => QRInviteType::AdminToServantInvite,
            'token' => str_repeat('z', 64),
            'created_by' => $adminA->id,
            'church_id' => $churchA->id,
            'class_id' => $classeA->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        // A class from church B is rejected even though the invite is valid.
        $classB = Classe::factory()->forChurch($churchB)->create();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Cross Church',
            'email' => 'cross-church@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $invite->token,
            'class_id' => $classB->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'cross-church@test.com']);
    }

    public function test_registration_ignores_a_client_supplied_church_id(): void
    {
        [$churchA, , $classeA] = $this->churchWithStageAndClass();
        $churchB = Church::factory()->create();
        $adminA = $this->churchAdmin($churchA);

        $invite = QRInvite::create([
            'type' => QRInviteType::AdminToServantInvite,
            'token' => str_repeat('w', 64),
            'created_by' => $adminA->id,
            'church_id' => $churchA->id,
            'class_id' => $classeA->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        // The client tries to smuggle church B in. church_id is not in
        // $fillable for this flow and is always taken from the invite.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Smuggler',
            'email' => 'smuggler@test.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invite_token' => $invite->token,
            'church_id' => $churchB->id,
        ])->assertStatus(201);

        $user = User::where('email', 'smuggler@test.com')->firstOrFail();
        $this->assertSame($churchA->id, $user->church_id, 'church_id must come from the invite, not the client.');
    }

    // -----------------------------------------------------------------
    // 14/15. Church isolation for the logged-in servant
    // -----------------------------------------------------------------

    public function test_servant_sees_only_own_church_data(): void
    {
        [$churchA, , $classeA] = $this->churchWithStageAndClass();
        $churchB = Church::factory()->create();
        $classeB = Classe::factory()->forChurch($churchB)->create();

        $servantA = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $churchA->id,
            'class_id' => $classeA->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        $memberA = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchA->id,
            'class_id' => $classeA->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $memberB = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $churchB->id,
            'class_id' => $classeB->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $token = $this->login($servantA)->json('data.token');

        // 14. Own church data is reachable and correctly scoped.
        $members = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/users/members')
            ->assertStatus(200);

        $returnedIds = collect($members->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($memberA->id, $returnedIds);
        $this->assertSame(
            [$churchA->id],
            collect($members->json('data'))->pluck('church_id')->unique()->values()->all(),
            'A member list must never mix tenants.'
        );

        // 15. Another church is neither listed nor directly reachable.
        $this->assertNotContains($memberB->id, $returnedIds);
        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/users/{$memberB->id}")
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // 16. UserResource serialization on the login response
    // -----------------------------------------------------------------

    public function test_login_response_serializes_the_relationship_keys(): void
    {
        [$church, , $classe] = $this->churchWithStageAndClass();
        $admin = $this->churchAdmin($church);

        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'class_id' => $classe->id,
            'created_by' => $admin->id,
            'application_status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        $user = $this->login($servant)->assertStatus(200)->json('data.user');

        // Non-optional keys are always present, null when there is no value.
        foreach (['church', 'servant', 'created_by', 'classe', 'role', 'role_label', 'email_verified_at'] as $key) {
            $this->assertArrayHasKey($key, $user, "login payload must always contain `{$key}`");
        }

        $this->assertSame($church->id, $user['church']['id']);
        $this->assertSame($classe->id, $user['classe']['id']);
        $this->assertSame($admin->id, $user['created_by']['id']);
        $this->assertNull($user['servant']);
        $this->assertSame('servant', $user['role']);
        $this->assertSame('Servant', $user['role_label']);

        // Secrets must never appear in the payload.
        $this->assertArrayNotHasKey('password', $user);
        $this->assertArrayNotHasKey('email_verification_token', $user);
    }

    // -----------------------------------------------------------------
    // Remediation command
    // -----------------------------------------------------------------

    public function test_verify_invited_accounts_command_backfills_only_proven_rows(): void
    {
        [$church, , $classe] = $this->churchWithStageAndClass();
        $admin = $this->churchAdmin($church);

        // Invitation actually consumed by the user -> eligible.
        $eligibleInvite = QRInvite::create([
            'type' => QRInviteType::AdminToServantInvite,
            'token' => str_repeat('1', 64),
            'created_by' => $admin->id,
            'church_id' => $church->id,
            'class_id' => $classe->id,
            'expires_at' => now()->addHours(4),
            'is_single_use' => true,
            'max_uses' => 1,
            'use_count' => 0,
        ]);

        $eligible = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'invite_id' => $eligibleInvite->id,
            'application_status' => 'approved',
            'email_verified_at' => null,
        ]);
        $eligibleInvite->forceFill(['use_count' => 1, 'used_by' => $eligible->id, 'used_at' => now()])->save();

        // invite_id points at an invite that was never consumed -> skipped.
        $unproven = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'invite_id' => $eligibleInvite->id,
            'application_status' => 'approved',
            'email_verified_at' => null,
        ]);

        // No invitation at all -> never touched by the command.
        $manual = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'invite_id' => null,
            'application_status' => 'approved',
            'email_verified_at' => null,
        ]);

        // Dry run must not write.
        $this->assertSame(0, $this->artisan('app:verify-invited-accounts', ['--dry-run' => true]));
        $this->assertNull($eligible->fresh()->email_verified_at);

        $this->assertSame(0, $this->artisan('app:verify-invited-accounts'));

        $this->assertNotNull($eligible->fresh()->email_verified_at, 'Consumed invitation is the proof.');
        $this->assertNull($unproven->fresh()->email_verified_at, 'Unproven invite must be skipped.');
        $this->assertNull($manual->fresh()->email_verified_at, 'No invitation means no proof.');
    }
}
