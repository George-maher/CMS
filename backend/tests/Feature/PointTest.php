<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_points_balance(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/points/balance');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['balance']]);
    }

    public function test_user_can_view_points_history(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/points/history');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }
}
