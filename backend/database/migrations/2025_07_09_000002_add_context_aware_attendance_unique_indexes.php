<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_event_unique');

        DB::statement("
            CREATE UNIQUE INDEX attendances_user_context_date_unique
            ON attendances (church_id, user_id, attendance_context_id, (attended_at::date))
            WHERE attendance_context_id IS NOT NULL
        ");

        DB::statement("
            CREATE UNIQUE INDEX attendances_user_event_date_unique
            ON attendances (church_id, user_id, event_id, (attended_at::date))
            WHERE event_id IS NOT NULL
        ");

        DB::statement("
            CREATE UNIQUE INDEX attendances_user_date_plain_unique
            ON attendances (church_id, user_id, (attended_at::date))
            WHERE event_id IS NULL AND attendance_context_id IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendances_user_context_date_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_event_date_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_plain_unique');

        DB::statement("
            CREATE UNIQUE INDEX attendances_user_date_unique
            ON attendances (user_id, (attended_at::date))
            WHERE event_id IS NULL
        ");

        DB::statement("
            CREATE UNIQUE INDEX attendances_user_date_event_unique
            ON attendances (user_id, (attended_at::date), event_id)
            WHERE event_id IS NOT NULL
        ");
    }
};
