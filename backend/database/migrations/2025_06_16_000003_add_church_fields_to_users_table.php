<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('church_id')->nullable()->constrained('churches')->nullOnDelete()->after('id');
            $table->foreignId('church_application_id')->nullable()->constrained('church_applications')->nullOnDelete()->after('church_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('church_id');
            $table->dropConstrainedForeignId('church_application_id');
        });
    }
};
