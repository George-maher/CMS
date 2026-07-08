<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add attended_date column
        Schema::table('attendances', function (Blueprint $table) {
            $table->date('attended_date')->nullable()->after('attended_at');
        });

        // 2. Backfill from attended_at
        $driver = DB::connection()->getDriverName();
        $dateExpr = $driver === 'sqlite' ? "date(attended_at)" : "attended_at::date";
        DB::statement("UPDATE attendances SET attended_date = {$dateExpr}");

        // 3. Drop old expression-based unique indexes
        DB::statement('DROP INDEX IF EXISTS attendances_user_context_date_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_event_date_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_plain_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_context_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_event_context_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_event_unique');

        // 4. Create new unique indexes using attended_date column
        DB::statement("
            CREATE UNIQUE INDEX attendances_user_context_date_unique
            ON attendances (church_id, user_id, attendance_context_id, attended_date)
            WHERE attendance_context_id IS NOT NULL
        ");

        DB::statement("
            CREATE UNIQUE INDEX attendances_user_event_date_unique
            ON attendances (church_id, user_id, event_id, attended_date)
            WHERE event_id IS NOT NULL
        ");

        DB::statement("
            CREATE UNIQUE INDEX attendances_user_date_plain_unique
            ON attendances (church_id, user_id, attended_date)
            WHERE event_id IS NULL AND attendance_context_id IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendances_user_context_date_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_event_date_unique');
        DB::statement('DROP INDEX IF EXISTS attendances_user_date_plain_unique');

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('attended_date');
        });

        $driver = DB::connection()->getDriverName();
        $dateExpr = $driver === 'sqlite' ? 'date(attended_at)' : '(attended_at::date)';

        DB::statement("
            CREATE UNIQUE INDEX attendances_user_context_date_unique
            ON attendances (church_id, user_id, attendance_context_id, {$dateExpr})
            WHERE attendance_context_id IS NOT NULL
        ");

        DB::statement("
            CREATE UNIQUE INDEX attendances_user_event_date_unique
            ON attendances (church_id, user_id, event_id, {$dateExpr})
            WHERE event_id IS NOT NULL
        ");

        DB::statement("
            CREATE UNIQUE INDEX attendances_user_date_plain_unique
            ON attendances (church_id, user_id, {$dateExpr})
            WHERE event_id IS NULL AND attendance_context_id IS NULL
        ");
    }
};
