<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserResourceNullClasseTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_resource_handles_null_classe(): void
    {
        $user = User::factory()->create([
            'role' => 'servant',
            'class_id' => null,
            'email_verified_at' => now(),
        ]);

        $resource = new \App\Http\Resources\UserResource($user);
        $array = $resource->resolve();

        $this->assertArrayHasKey('classe', $array);
        $this->assertNull($array['classe'], 'classe should be null when no class assigned');
    }

    public function test_user_resource_handles_null_classe_when_loaded(): void
    {
        $user = User::factory()->create([
            'role' => 'servant',
            'class_id' => null,
            'email_verified_at' => now(),
        ]);

        // Load the classe relationship (will be null)
        $user->load('classe');
        
        $resource = new \App\Http\Resources\UserResource($user);
        $array = $resource->resolve();

        $this->assertArrayHasKey('classe', $array);
        $this->assertNull($array['classe'], 'classe should be null when relationship loaded but null');
    }
}