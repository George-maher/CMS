<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('church_applications', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('admin_notes');
        });
    }

    public function down(): void
    {
        Schema::table('church_applications', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
