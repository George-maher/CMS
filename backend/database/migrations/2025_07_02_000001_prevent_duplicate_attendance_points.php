<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Prevent duplicate points for same reference ──────────
        // Check index existence before creating to avoid transaction abort
        $indexExists = DB::selectOne("
            SELECT 1 FROM pg_class WHERE relname = 'points_reference_unique'
        ");
        if (!$indexExists) {
            DB::statement("
                CREATE UNIQUE INDEX points_reference_unique
                ON points (reference_type, reference_id)
                WHERE reference_type IS NOT NULL AND reference_id IS NOT NULL
            ");
        }

        // ── 2. Fix attendance unique constraints ────────────────────
        // Drop the overly-broad (user_id, attended_at, church_id) unique constraint.
        // Must use ALTER TABLE DROP CONSTRAINT — it was created as a constraint
        // (via $t->unique()), not a bare index. DROP INDEX would fail because
        // "constraint requires it".
        DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_user_date_church_unique');

        // Drop old partial indexes to recreate them with context_id included
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_event_unique');

        // Recreate with attendance_context_id included for proper session isolation
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS attendances_user_date_context_unique
            ON attendances (user_id, (attended_at::date), attendance_context_id)
            WHERE event_id IS NULL
        ");

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS attendances_user_date_event_context_unique
            ON attendances (user_id, (attended_at::date), event_id, attendance_context_id)
            WHERE event_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS points_reference_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_context_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_event_context_unique');

        // Restore previous indexes
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS attendances_user_date_unique
            ON attendances (user_id, (attended_at::date))
            WHERE event_id IS NULL
        ");
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS attendances_user_date_event_unique
            ON attendances (user_id, (attended_at::date), event_id)
            WHERE event_id IS NOT NULL
        ");
    }
};
