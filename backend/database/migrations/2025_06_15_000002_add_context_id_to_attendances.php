<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('attendance_context_id')
                ->nullable()
                ->after('event_id')
                ->constrained('attendance_contexts')
                ->nullOnDelete();

            $table->index('attendance_context_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['attendance_context_id']);
            $table->dropColumn('attendance_context_id');
        });
    }
};
