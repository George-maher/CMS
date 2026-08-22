<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password recovery no longer uses emailed reset links.
 * The Church Admin sets the new password directly after approval,
 * so the token columns are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Indexes must be dropped before the columns (required by SQLite).
        // Check existence first: some drivers collapse unique+index into one.
        $existing = array_column(Schema::getIndexes('password_reset_requests'), 'name');

        Schema::table('password_reset_requests', function (Blueprint $table) use ($existing) {
            if (in_array('password_reset_requests_token_unique', $existing, true)) {
                $table->dropUnique('password_reset_requests_token_unique');
            }
            if (in_array('password_reset_requests_token_index', $existing, true)) {
                $table->dropIndex('password_reset_requests_token_index');
            }
        });

        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->dropColumn(['token', 'token_expires_at', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->string('token', 64)->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
        });

        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->unique('token');
            $table->index('token');
        });
    }
};
