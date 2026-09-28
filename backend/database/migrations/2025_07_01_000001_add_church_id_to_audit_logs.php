<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('audit_logs', 'church_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->foreignId('church_id')
                    ->nullable()
                    ->constrained('churches')
                    ->nullOnDelete()
                    ->after('user_id');

                $table->index(['church_id', 'created_at'], 'audit_logs_church_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_church_created_idx');
            $table->dropConstrainedForeignId('church_id');
        });
    }
};
