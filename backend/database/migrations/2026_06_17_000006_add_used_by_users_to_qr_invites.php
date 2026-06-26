<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_invites', function (Blueprint $table) {
            $table->json('used_by_users')->nullable()->after('used_by');
        });
    }

    public function down(): void
    {
        Schema::table('qr_invites', function (Blueprint $table) {
            $table->dropColumn('used_by_users');
        });
    }
};
