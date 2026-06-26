<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('feedback_id')->nullable()->constrained('feedback')->nullOnDelete();
            $table->foreignId('points_id')->nullable()->constrained('points')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['feedback_id']);
            $table->dropForeign(['points_id']);
            $table->dropColumn(['feedback_id', 'points_id']);
        });
    }
};
