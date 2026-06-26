<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('member')->after('email');
            $table->foreignId('class_year_id')->nullable()->constrained('class_years')->nullOnDelete()->after('role');
            $table->string('phone', 20)->nullable()->after('class_year_id');
            $table->string('avatar')->nullable()->after('phone');
            $table->boolean('is_active')->default(true)->after('avatar');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->after('is_active');
            $table->softDeletes()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_year_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['role', 'phone', 'avatar', 'is_active', 'deleted_at']);
        });
    }
};
