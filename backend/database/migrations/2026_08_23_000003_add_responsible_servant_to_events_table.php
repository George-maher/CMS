<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('responsible_servant_id')->nullable()->constrained('users')->nullOnDelete()->after('created_by');
            $table->index('responsible_servant_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['responsible_servant_id']);
            $table->dropIndex(['responsible_servant_id']);
            $table->dropColumn('responsible_servant_id');
        });
    }
};
