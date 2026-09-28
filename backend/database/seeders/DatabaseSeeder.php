<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use \Illuminate\Database\Console\Seeds\WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,

            AttendanceContextSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
