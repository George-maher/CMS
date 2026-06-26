<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('member_id', 20)->nullable()->unique()->after('id');
            $table->index('member_id');
        });

        DB::statement('CREATE SEQUENCE IF NOT EXISTS users_member_id_seq START 1');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['member_id']);
            $table->dropColumn('member_id');
        });

        DB::statement('DROP SEQUENCE IF EXISTS users_member_id_seq');
    }
};
