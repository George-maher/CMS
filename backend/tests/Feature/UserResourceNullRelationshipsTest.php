<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserResourceNullRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_resource_handles_null_servant_when_loaded(): void
    {
        $user = User::factory()->create([
            'role' => 'servant',
            'servant_id' => null,
            'email_verified_at' => now(),
        ]);

        // Load the servant relationship (will be null)
        $user->load('servant');
        
        $resource = new \App\Http\Resources\UserResource($user);
        $array = $resource->resolve();

        $this->assertArrayHasKey('servant', $array);
        $this->assertNull($array['servant'], 'servant should be null when relationship loaded but null');
    }

    public function test_user_resource_handles_null_created_by_when_loaded(): void
    {
        $user = User::factory()->create([
            'role' => 'servant',
            'created_by' => null,
            'email_verified_at' => now(),
        ]);

        // Load the createdBy relationship (will be null)
        $user->load('createdBy');
        
        $resource = new \App\Http\Resources\UserResource($user);
        $array = $resource->resolve();

        $this->assertArrayHasKey('created_by', $array);
        $this->assertNull($array['created_by'], 'created_by should be null when relationship loaded but null');
    }

    public function test_user_resource_handles_null_church_when_loaded(): void
    {
        $user = User::factory()->create([
            'role' => 'servant',
            'church_id' => null,
            'email_verified_at' => now(),
        ]);

        // Load the church relationship (will be null)
        $user->load('church');
        
        $resource = new \App\Http\Resources\UserResource($user);
        $array = $resource->resolve();

        $this->assertArrayHasKey('church', $array);
        $this->assertNull($array['church'], 'church should be null when relationship loaded but null');
    }
}