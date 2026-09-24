<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Church;
use App\Models\Permission;
use App\Models\User;
use App\Services\SupabaseStorageService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class StorageEndpointAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private User $admin;

    private User $servant;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Permission::clearCache();

        $this->church = Church::factory()->create(['name' => 'Test Church']);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $this->church->id,
            'application_status' => 'approved',
        ]);

        $this->servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $this->church->id,
            'application_status' => 'approved',
        ]);

        $this->member = User::factory()->create([
            'role' => UserRole::Member,
            'church_id' => $this->church->id,
            'application_status' => 'approved',
        ]);
    }

    private function actingAsUser(User $user): self
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeader('Authorization', "Bearer $token");
    }

    /**
     * A real 1x1 PNG wrapped as an UploadedFile. The test runner does not
     * have the GD extension, so UploadedFile::fake()->image() is unavailable.
     */
    private function makePng(string $name = 'x.png'): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        $path = tempnam(sys_get_temp_dir(), 'test_png_');
        file_put_contents($path, $png);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function test_member_cannot_delete_files(): void
    {
        $this->actingAsUser($this->member)
            ->deleteJson('/api/v1/storage/delete/profiles', ['url' => 'https://example.com/profiles/x.png'])
            ->assertForbidden();
    }

    public function test_member_cannot_replace_files(): void
    {
        $this->actingAsUser($this->member)
            ->postJson('/api/v1/storage/replace/events', [
                'old_url' => 'https://example.com/events/x.png',
                'file' => $this->makePng(),
            ])
            ->assertForbidden();
    }

    public function test_member_cannot_upload_documents(): void
    {
        $this->actingAsUser($this->member)
            ->postJson('/api/v1/storage/upload-document', [
                'file' => $this->makePng(),
            ])
            ->assertForbidden();
    }

    public function test_member_cannot_upload_to_generic_bucket(): void
    {
        $this->actingAsUser($this->member)
            ->postJson('/api/v1/storage/upload/events', [
                'file' => $this->makePng(),
            ])
            ->assertForbidden();
    }

    public function test_servant_cannot_delete_files(): void
    {
        $this->actingAsUser($this->servant)
            ->deleteJson('/api/v1/storage/delete/profiles', ['url' => 'https://example.com/profiles/x.png'])
            ->assertForbidden();
    }

    public function test_member_cannot_upload_event_image(): void
    {
        $this->actingAsUser($this->member)
            ->postJson('/api/v1/storage/upload-event-image', [
                'file' => $this->makePng(),
            ])
            ->assertForbidden();
    }

    public function test_servant_can_upload_event_image(): void
    {
        $this->actingAsUser($this->servant)
            ->postJson('/api/v1/storage/upload-event-image', [
                'file' => $this->makePng(),
            ])
            ->assertStatus(201);
    }

    public function test_servant_can_upload_own_profile_image(): void
    {
        $this->actingAsUser($this->servant)
            ->postJson('/api/v1/storage/upload-profile-image', [
                'file' => $this->makePng(),
            ])
            ->assertStatus(201);
    }

    public function test_admin_upload_with_unsupported_bucket_is_rejected(): void
    {
        $this->actingAsUser($this->admin)
            ->postJson('/api/v1/storage/upload/custom_bucket', [
                'file' => $this->makePng(),
            ])
            ->assertStatus(422)
            ->assertJson(['code' => 'VALIDATION_ERROR']);
    }

    public function test_admin_upload_with_dotted_bucket_is_rejected(): void
    {
        $this->actingAsUser($this->admin)
            ->postJson('/api/v1/storage/upload/events.png', [
                'file' => $this->makePng(),
            ])
            ->assertStatus(422)
            ->assertJson(['code' => 'VALIDATION_ERROR']);
    }

    public function test_admin_can_upload_to_allowed_bucket(): void
    {
        $this->actingAsUser($this->admin)
            ->postJson('/api/v1/storage/upload/profiles', [
                'file' => $this->makePng(),
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['url']);
    }

    public function test_unauthenticated_user_cannot_access_storage_endpoints(): void
    {
        $this->postJson('/api/v1/storage/upload-document', [
            'file' => $this->makePng(),
        ])->assertUnauthorized();
    }

    public function test_supabase_storage_rejects_url_bucket_mismatch_before_mutation(): void
    {
        config([
            'supabase-storage.project_url' => 'https://project.supabase.co',
            'supabase-storage.service_role_key' => 'test-key',
            'supabase-storage.base_url' => '',
        ]);

        $service = new SupabaseStorageService;

        $this->assertFalse($service->deleteFile(
            'https://project.supabase.co/storage/v1/object/public/events/event.png',
            'profiles',
        ));
    }
}
