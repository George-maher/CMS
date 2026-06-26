<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('church_applications', function (Blueprint $table) {
            $table->string('church_permission_doc_path')->nullable()->after('back_id_path');
        });
    }

    public function down(): void
    {
        Schema::table('church_applications', function (Blueprint $table) {
            $table->dropColumn('church_permission_doc_path');
        });
    }
};
