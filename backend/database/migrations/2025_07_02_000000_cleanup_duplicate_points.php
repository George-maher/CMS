<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clean up duplicate points before adding unique constraint
        // Keep the first record, delete duplicates
        DB::statement("
            DELETE FROM points
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM points
                WHERE reference_type IS NOT NULL AND reference_id IS NOT NULL
                GROUP BY reference_type, reference_id
            )
            AND reference_type IS NOT NULL
            AND reference_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        // No rollback needed for cleanup
    }
};