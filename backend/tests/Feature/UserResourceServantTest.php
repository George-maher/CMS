<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserResourceServantTest extends TestCase
{
    use RefreshDatabase;

    public function test_servant_user_resource_includes_church_and_created_by(): void
    {
        $church = Church::factory()->create();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'church_id' => $church->id,
            'email_verified_at' => now(),
        ]);

        $servant = User::factory()->create([
            'role' => UserRole::Servant,
            'church_id' => $church->id,
            'created_by' => $admin->id,
            'servant_id' => null,
            'email_verified_at' => now(),
        ]);

        // Load all relationships as done in login
        $servant->load(['classe', 'servant', 'church', 'churchApplication']);
        
        $resource = new \App\Http\Resources\UserResource($servant);
        $array = $resource->resolve();

        // Check that church is included
        $this->assertArrayHasKey('church', $array);
        $this->assertNotNull($array['church']);
        $this->assertEquals($church->id, $array['church']['id']);

        // Check that created_by is included
        $this->assertArrayHasKey('created_by', $array);
        $this->assertNotNull($array['created_by']);
        $this->assertEquals($admin->id, $array['created_by']['id']);

        // Check that servant is NOT included (null)
        $this->assertArrayNotHasKey('servant', $array);

        // Check that classe is included (null)
        $this->assertArrayHasKey('classe', $array);
        $this->assertNull($array['classe']);
    }
}