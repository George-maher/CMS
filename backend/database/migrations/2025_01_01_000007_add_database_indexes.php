<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'attended_at']);
            $table->index('attended_at');
        });

        Schema::table('points', function (Blueprint $table) {
            $table->index(['user_id', 'type']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::table('qr_invites', function (Blueprint $table) {
            $table->index('type');
            $table->index('expires_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
        });

        Schema::table('qr_invites', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropIndex(['expires_at']);
        });

        Schema::table('points', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'type']);
            $table->dropIndex(['reference_type', 'reference_id']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['attended_at']);
            $table->unique(['user_id', 'attended_at']);
        });
    }
};
