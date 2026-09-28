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
            if (!Schema::hasColumn('users', 'address')) {
                $table->text('address')->nullable()->after('avatar');
            }
        });

        $this->createIndexIfNotExists('attendances', 'attendances_church_id_idx', 'church_id');
        $this->createIndexIfNotExists('attendances', 'attendances_user_id_idx', 'user_id');
        $this->createIndexIfNotExists('events', 'events_church_id_idx', 'church_id');
        $this->createIndexIfNotExists('class_years', 'class_years_church_id_idx', 'church_id');
        $this->createIndexIfNotExists('points', 'points_church_id_idx', 'church_id');
        $this->createIndexIfNotExists('qr_invites', 'qr_invites_church_id_idx', 'church_id');
        $this->createIndexIfNotExists('qr_invites', 'qr_invites_created_by_idx', 'created_by');
        $this->createIndexIfNotExists('feedback', 'feedback_church_id_idx', 'church_id');
        $this->createIndexIfNotExists('feedback', 'feedback_class_year_id_idx', 'class_year_id');
        $this->createIndexIfNotExists('daily_verses', 'daily_verses_church_id_idx', 'church_id');
        $this->createIndexIfNotExists('attendance_contexts', 'attendance_contexts_church_id_idx', 'church_id');
        $this->createIndexIfNotExists('users', 'users_church_id_idx', 'church_id');
        $this->createIndexIfNotExists('users', 'users_class_year_id_idx', 'class_year_id');
        $this->createIndexIfNotExists('users', 'users_servant_id_idx', 'servant_id');
        $this->createIndexIfNotExists('users', 'users_attendance_qr_token_idx', 'attendance_qr_token');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'address')) {
                $table->dropColumn('address');
            }
        });

        $tables = [
            'attendances' => ['attendances_church_id_idx', 'attendances_user_id_idx'],
            'events' => ['events_church_id_idx'],
            'class_years' => ['class_years_church_id_idx'],
            'points' => ['points_church_id_idx'],
            'qr_invites' => ['qr_invites_church_id_idx', 'qr_invites_created_by_idx'],
            'feedback' => ['feedback_church_id_idx', 'feedback_class_year_id_idx'],
            'daily_verses' => ['daily_verses_church_id_idx'],
            'attendance_contexts' => ['attendance_contexts_church_id_idx'],
            'users' => ['users_church_id_idx', 'users_class_year_id_idx', 'users_servant_id_idx', 'users_attendance_qr_token_idx'],
        ];

        foreach ($tables as $table => $indexes) {
            foreach ($indexes as $index) {
                try {
                    Schema::table($table, function (Blueprint $t) use ($index) {
                        $t->dropIndex($index);
                    });
                } catch (\Exception $e) {
                    // Index may not exist
                }
            }
        }
    }

    private function createIndexIfNotExists(string $table, string $indexName, string $column): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($column, $indexName) {
                $t->index($column, $indexName);
            });
        } catch (\Exception $e) {
            // Index may already exist
        }
    }
};
