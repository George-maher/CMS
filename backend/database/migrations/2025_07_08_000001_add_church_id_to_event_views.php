<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_views', function (Blueprint $table) {
            $table->foreignId('church_id')->after('id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable()->after('viewed_at');
        });

        DB::statement('UPDATE event_views SET church_id = (SELECT church_id FROM events WHERE events.id = event_views.event_id)');

        Schema::table('event_views', function (Blueprint $table) {
            $table->foreignId('church_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('event_views', function (Blueprint $table) {
            $table->dropForeign(['church_id']);
            $table->dropColumn(['church_id', 'created_at']);
        });
    }
};
