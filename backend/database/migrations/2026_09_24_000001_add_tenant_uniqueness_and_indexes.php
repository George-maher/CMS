<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // membership_requests.email was globally unique — that leaked the
        // existence of an applicant across churches and blocked two churches
        // from ever receiving a request from the same email address.
        // Uniqueness becomes tenant-scoped: one request per email per church.
        Schema::table('membership_requests', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->unique(['church_id', 'email']);
        });

        // Missing indexes for the hot tenant/scope join columns.
        Schema::table('users', function (Blueprint $table) {
            $table->index('class_id');
            $table->index('stage_id');
        });

        // The composite primary key (class_id, user_id) cannot serve queries
        // that filter by servant first (user_id → classes lookups).
        Schema::table('class_servant', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('membership_requests', function (Blueprint $table) {
            $table->dropUnique(['church_id', 'email']);
            $table->unique(['email']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['class_id']);
            $table->dropIndex(['stage_id']);
        });

        Schema::table('class_servant', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
