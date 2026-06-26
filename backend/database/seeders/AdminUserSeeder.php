<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'platform@churchmanager.app'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('password'),
                'role' => UserRole::PlatformAdmin,
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'admin@churchmanager.app'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin,
                'is_active' => true,
            ]
        );
    }
}
