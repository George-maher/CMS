<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attendances — context-based queries
        $this->createIndexIfNotExists('attendances', 'attendances_attendance_context_id_idx', ['attendance_context_id']);

        // Attendances — composite for dashboard aggregation
        $this->createIndexIfNotExists('attendances', 'attendances_church_attended_idx', ['church_id', 'attended_at']);

        // Attendances — servant performance
        $this->createIndexIfNotExists('attendances', 'attendances_recorded_attended_idx', ['recorded_by', 'attended_at']);

        // Events — date-range queries
        $this->createIndexIfNotExists('events', 'events_church_date_idx', ['church_id', 'event_date']);

        // QR Invites — church-scoped cleanup queries
        $this->createIndexIfNotExists('qr_invites', 'qr_invites_church_expires_idx', ['church_id', 'expires_at']);

        // Points — user + church composite
        $this->createIndexIfNotExists('points', 'points_user_church_idx', ['user_id', 'church_id']);

        // Users — role-based listing scoped to church
        $this->createIndexIfNotExists('users', 'users_church_role_idx', ['church_id', 'role']);

        // Users — active membership queries
        $this->createIndexIfNotExists('users', 'users_church_active_idx', ['church_id', 'is_active']);

        // Feedback — user history
        $this->createIndexIfNotExists('feedback', 'feedback_user_created_idx', ['user_id', 'created_at']);

        // Daily verses — active lookup
        $this->createIndexIfNotExists('daily_verses', 'daily_verses_active_church_idx', ['is_active', 'church_id']);

        // Membership requests — admin review queries
        $this->createIndexIfNotExists('membership_requests', 'membership_req_church_created_idx', ['church_id', 'created_at']);

        // Class years — active listing
        $this->createIndexIfNotExists('class_years', 'class_years_active_church_idx', ['is_active', 'church_id']);
    }

    public function down(): void
    {
        $indexes = [
            'attendances' => [
                'attendances_attendance_context_id_idx',
                'attendances_church_attended_idx',
                'attendances_recorded_attended_idx',
            ],
            'events' => ['events_church_date_idx'],
            'qr_invites' => ['qr_invites_church_expires_idx'],
            'points' => ['points_user_church_idx'],
            'users' => ['users_church_role_idx', 'users_church_active_idx'],
            'feedback' => ['feedback_user_created_idx'],
            'daily_verses' => ['daily_verses_active_church_idx'],
            'membership_requests' => ['membership_req_church_created_idx'],
            'class_years' => ['class_years_active_church_idx'],
        ];

        foreach ($indexes as $table => $names) {
            foreach ($names as $name) {
                try {
                    Schema::table($table, fn(Blueprint $t) => $t->dropIndex($name));
                } catch (\Exception $e) {
                    // Index may not exist
                }
            }
        }
    }

    private function createIndexIfNotExists(string $table, string $name, array $columns): void
    {
        try {
            Schema::table($table, fn(Blueprint $t) => $t->index($columns, $name));
        } catch (\Exception $e) {
            // Index may already exist
        }
    }
};
