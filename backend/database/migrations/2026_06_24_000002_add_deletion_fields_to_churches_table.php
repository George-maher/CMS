<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('churches', function (Blueprint $table) {
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete()->after('is_suspended');
            $table->string('deletion_type')->nullable()->comment('soft or hard')->after('deleted_by');
            $table->timestamp('recoverable_until')->nullable()->after('deletion_type');
        });
    }

    public function down(): void
    {
        Schema::table('churches', function (Blueprint $table) {
            $table->dropForeign(['deleted_by']);
            $table->dropColumn(['deleted_by', 'deletion_type', 'recoverable_until']);
        });
    }
};
