<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REMOVED: This was an identical duplicate of
        // 2025_07_02_000000_cleanup_duplicate_points.php.
        // Keeping this file empty to avoid breaking existing
        // environments that already ran this migration.
    }

    public function down(): void
    {
    }
};
