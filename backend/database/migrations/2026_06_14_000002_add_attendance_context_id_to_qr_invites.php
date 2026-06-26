<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_invites', function (Blueprint $table) {
            $table->foreignId('attendance_context_id')->nullable()->after('class_year_id')
                ->constrained('attendance_contexts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('qr_invites', function (Blueprint $table) {
            $table->dropForeign(['attendance_context_id']);
            $table->dropColumn('attendance_context_id');
        });
    }
};
