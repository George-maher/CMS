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
        User::updateOrCreate(
            ['email' => 'platform@churchmanager.app'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('chknestyfou2*'),
                'role' => UserRole::PlatformAdmin,
                'is_active' => true,
                'application_status' => 'approved',
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@churchmanager.app'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin,
                'is_active' => true,
                'application_status' => 'approved',
            ]
        );
    }
}
