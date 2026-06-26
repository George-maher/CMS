<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columnChecks = [
        'users' => [
            'role', 'class_year_id', 'phone', 'avatar', 'is_active',
            'created_by', 'attendance_qr_token', 'servant_id', 'invite_id',
            'birthday', 'church_id', 'church_application_id', 'application_status',
            'address', 'member_address', 'email_verification_token',
        ],
        'events' => [
            'class_year_id', 'type', 'image', 'church_id',
        ],
        'attendances' => [
            'class_year_id', 'event_id', 'attendance_context_id', 'church_id',
        ],
        'points' => [
            'church_id',
        ],
        'qr_invites' => [
            'class_year_id', 'church_id',
        ],
        'feedback' => [
            'class_year_id', 'user_id', 'is_anonymous', 'church_id',
        ],
        'daily_verses' => [
            'church_id',
        ],
        'attendance_contexts' => [
            'church_id',
        ],
        'audit_logs' => [
            'church_id',
        ],
        'class_years' => [
            'church_id',
        ],
        'church_applications' => [
            'rejection_reason', 'church_permission_doc_path',
        ],
    ];

    public function up(): void
    {
        foreach ($this->columnChecks as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $columns) {
                foreach ($columns as $column) {
                    if (!Schema::hasColumn($table, $column)) {
                        $this->addSafeColumn($t, $table, $column);
                    }
                }
            });
        }
    }

    private function addSafeColumn(Blueprint $t, string $table, string $column): void
    {
        $col = match ($column) {
            'role' => $t->string('role', 20)->default('member'),
            'class_year_id' => $t->foreignId('class_year_id')->nullable()->constrained('class_years')->nullOnDelete(),
            'phone' => $t->string('phone', 20)->nullable(),
            'avatar' => $t->string('avatar')->nullable(),
            'is_active' => $t->boolean('is_active')->default(true),
            'created_by' => $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(),
            'attendance_qr_token' => $t->string('attendance_qr_token', 100)->nullable()->unique(),
            'servant_id' => $t->foreignId('servant_id')->nullable()->constrained('users')->nullOnDelete(),
            'invite_id' => $t->foreignId('invite_id')->nullable()->constrained('qr_invites')->nullOnDelete(),
            'birthday' => $t->date('birthday')->nullable(),
            'church_id' => $this->addChurchId($t, $table),
            'church_application_id' => $t->foreignId('church_application_id')->nullable()->constrained('church_applications')->nullOnDelete(),
            'application_status' => $t->string('application_status', 20)->default('approved'),
            'address' => $t->text('address')->nullable(),
            'member_address' => $t->string('member_address', 500)->nullable(),
            'email_verification_token' => $t->string('email_verification_token', 64)->nullable()->unique(),
            'type' => $t->string('type', 20)->default('service'),
            'image' => $t->string('image', 500)->nullable(),
            'event_id' => $t->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete(),
            'attendance_context_id' => $t->foreignId('attendance_context_id')->nullable()->constrained('attendance_contexts')->nullOnDelete(),
            'user_id' => $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(),
            'is_anonymous' => $t->boolean('is_anonymous')->default(false),
            'rejection_reason' => $t->text('rejection_reason')->nullable(),
            'church_permission_doc_path' => $t->string('church_permission_doc_path')->nullable(),
            default => null,
        };

        if ($col === null && $column !== 'class_year_id') {
            $t->string($column)->nullable();
        }
    }

    private function addChurchId(Blueprint $t, string $table): void
    {
        if ($table === 'users') {
            $t->foreignId('church_id')->nullable()->constrained('churches')->nullOnDelete();
        } elseif (Schema::hasTable('churches')) {
            $t->foreignId('church_id')->nullable()->constrained('churches')->nullOnDelete();
        } else {
            $t->unsignedBigInteger('church_id')->nullable();
        }
    }

    public function down(): void
    {
        // No rollback — this is a safe guard migration
    }
};
