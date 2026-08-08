<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_invites', function (Blueprint $table) {
            $table->string('client_request_id', 64)->nullable()->after('token');
        });

        Schema::table('qr_invites', function (Blueprint $table) {
            // Idempotency key: one invite per (creator, client key). NULL keys are
            // allowed multiple times, so legacy/unknown requests keep working.
            $table->unique(['created_by', 'client_request_id'], 'qr_invites_creator_request_unique');
        });
    }

    public function down(): void
    {
        Schema::table('qr_invites', function (Blueprint $table) {
            $table->dropUnique('qr_invites_creator_request_unique');
            $table->dropColumn('client_request_id');
        });
    }
};
