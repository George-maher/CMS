<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attendances — class-based queries
        DB::statement('CREATE INDEX IF NOT EXISTS attendances_class_year_attended_idx ON attendances (class_year_id, attended_at)');

        // Attendances — recorder queries (servant performance)
        DB::statement('CREATE INDEX IF NOT EXISTS attendances_recorded_by_idx ON attendances (recorded_by)');

        // Points — user totals
        DB::statement('CREATE INDEX IF NOT EXISTS points_user_id_created_idx ON points (user_id, created_at)');

        // Events — class-based listing
        DB::statement('CREATE INDEX IF NOT EXISTS events_class_year_active_idx ON events (class_year_id, is_active)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendances_class_year_attended_idx');
        DB::statement('DROP INDEX IF EXISTS attendances_recorded_by_idx');
        DB::statement('DROP INDEX IF EXISTS points_user_id_created_idx');
        DB::statement('DROP INDEX IF EXISTS events_class_year_active_idx');
    }
};
