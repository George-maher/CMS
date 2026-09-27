<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordHashTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_password_not_double_hashed(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('Test@1234'),
        ]);

        // Check the stored password hash
        $storedHash = $user->getAttribute('password'); // Get raw attribute without cast
        $this->assertStringStartsWith('$2y$', $storedHash);

        // The password should verify correctly
        $this->assertTrue(Hash::check('Test@1234', $user->password), 'Password should verify correctly');
    }

    public function test_manual_create_password_not_double_hashed(): void
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'test@test.com',
            'password' => Hash::make('Test@1234'),
            'role' => 'member',
        ]);

        // Check the stored password hash
        $storedHash = $user->getAttribute('password');
        $this->assertStringStartsWith('$2y$', $storedHash);

        // The password should verify correctly
        $this->assertTrue(Hash::check('Test@1234', $user->password), 'Password should verify correctly');
    }

    public function test_register_flow_password_not_double_hashed(): void
    {
        // Simulate the register flow
        $plainPassword = 'Test@1234';
        $hashedOnce = Hash::make($plainPassword);
        
        // This is what AuthService::register does
        $data = [
            'name' => 'Test',
            'email' => 'register@test.com',
            'password' => $hashedOnce, // Already hashed
            'role' => 'member',
        ];
        
        $user = User::create($data);
        
        // Check the stored password hash
        $storedHash = $user->getAttribute('password');
        $this->assertStringStartsWith('$2y$', $storedHash);
        
        // If double-hashed, the stored hash would be Hash::make($hashedOnce)
        // If correctly hashed once, the stored hash would be $hashedOnce
        
        // The password should verify correctly
        $verifies = Hash::check($plainPassword, $user->password);
        $this->assertTrue($verifies, 'Password should verify correctly after register flow');
        
        // Also check that it's not double-hashed by checking if Hash::check(hashedOnce, storedHash) is false
        $isDoubleHashed = Hash::check($hashedOnce, $storedHash);
        $this->assertFalse($isDoubleHashed, 'Password should NOT be double-hashed');
    }
}