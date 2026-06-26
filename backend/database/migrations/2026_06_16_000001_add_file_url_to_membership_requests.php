<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_requests', function (Blueprint $table) {
            $table->string('file_url')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('membership_requests', function (Blueprint $table) {
            $table->dropColumn('file_url');
        });
    }
};
