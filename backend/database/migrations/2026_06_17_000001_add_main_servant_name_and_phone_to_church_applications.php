<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('church_applications', 'main_servant_name')) {
            Schema::table('church_applications', function (Blueprint $table) {
                $table->string('main_servant_name')->nullable()->after('priest_name');
                $table->string('phone', 20)->nullable()->after('priest_phone');
            });
        }
    }

    public function down(): void
    {
        Schema::table('church_applications', function (Blueprint $table) {
            $table->dropColumn(['main_servant_name', 'phone']);
        });
    }
};
