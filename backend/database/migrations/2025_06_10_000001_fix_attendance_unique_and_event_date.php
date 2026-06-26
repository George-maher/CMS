<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Make events.event_date nullable ─────────────────────
        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('event_date')->nullable()->change();
        });

        // ── 2. Fix attendance duplicate prevention ──────────────────
        // Drop the simple index on attended_at created by migration 000007
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['attended_at']);
        });

        // Since we dropped the unique(user_id, attended_at) in 000007 and
        // only added a plain index, we need to re-add proper protection.
        // PostgreSQL supports partial unique indexes on expressions.
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

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_event_unique');

        Schema::table('attendances', function (Blueprint $table) {
            $table->index('attended_at');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('event_date')->nullable(false)->change();
        });
    }
};
