<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_contexts', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->unique(['church_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_contexts', function (Blueprint $table) {
            $table->dropUnique(['church_id', 'slug']);
            $table->unique('slug');
        });
    }
};
