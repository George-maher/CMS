<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users')
            ->whereNull('attendance_qr_token')
            ->get();

        foreach ($users as $user) {
            do {
                $token = Str::random(64);
            } while (DB::table('users')->where('attendance_qr_token', $token)->exists());

            DB::table('users')
                ->where('id', $user->id)
                ->update(['attendance_qr_token' => $token]);
        }
    }

    public function down(): void
    {
    }
};
