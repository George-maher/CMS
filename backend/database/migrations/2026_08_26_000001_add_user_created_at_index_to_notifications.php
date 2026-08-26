<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The notification list query filters by user_id and orders by created_at
     * DESC on every app load and panel open. A composite (user_id, created_at)
     * index serves both the filter and the sort without a separate sort step.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (! $this->indexExists('notifications_user_id_created_at_index')) {
                $table->index(['user_id', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
        });
    }

    private function indexExists(string $name): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $result = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?",
                [$name]
            );

            return count($result) > 0;
        }

        $result = DB::select('SELECT 1 FROM pg_indexes WHERE indexname = ?', [$name]);

        return count($result) > 0;
    }
};
