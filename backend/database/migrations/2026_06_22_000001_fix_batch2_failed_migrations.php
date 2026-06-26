<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 2026_06_17_000001 — main_servant_name & phone on church_applications
        // ============================================================
        if (!Schema::hasColumn('church_applications', 'main_servant_name')) {
            Schema::table('church_applications', function (Blueprint $table) {
                $table->string('main_servant_name')->nullable()->after('priest_name');
                $table->string('phone', 20)->nullable()->after('priest_phone');
            });
        }

        // ============================================================
        // 2026_06_17_000002 — main_servant_name & phone on churches
        // ============================================================
        if (!Schema::hasColumn('churches', 'main_servant_name')) {
            Schema::table('churches', function (Blueprint $table) {
                $table->string('main_servant_name')->nullable()->after('priest_name');
                $table->string('phone', 20)->nullable()->after('priest_phone');
            });
        }

        // ============================================================
        // 2026_06_17_000003 — make priest_phone nullable on church_applications
        // ============================================================
        if (Schema::hasColumn('church_applications', 'priest_phone')) {
            Schema::table('church_applications', function (Blueprint $table) {
                $table->string('priest_phone', 20)->nullable()->change();
            });
        }

        // ============================================================
        // 2026_06_17_000004 — make priest_phone & service_name nullable on churches
        // ============================================================
        if (Schema::hasColumn('churches', 'priest_phone')) {
            Schema::table('churches', function (Blueprint $table) {
                $table->string('priest_phone', 20)->nullable()->change();
            });
        }
        if (Schema::hasColumn('churches', 'service_name')) {
            Schema::table('churches', function (Blueprint $table) {
                $table->string('service_name')->nullable()->change();
            });
        }

        // ============================================================
        // 2026_06_17_000005 — create event_targets table + is_all_classes on events
        // ============================================================
        if (!Schema::hasTable('event_targets')) {
            Schema::create('event_targets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('event_id')->constrained()->cascadeOnDelete();
                $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
                $table->boolean('is_all_classes')->default(false);
                $table->foreignId('church_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();

                $table->index(['event_id', 'class_id']);
                $table->index('church_id');
            });
        }

        if (!Schema::hasColumn('events', 'is_all_classes')) {
            Schema::table('events', function (Blueprint $table) {
                $table->boolean('is_all_classes')->default(false)->after('is_active');
                $table->index('is_all_classes');
            });
        }

        // ============================================================
        // 2026_06_17_000006 — used_by_users JSON on qr_invites
        // ============================================================
        if (!Schema::hasColumn('qr_invites', 'used_by_users')) {
            Schema::table('qr_invites', function (Blueprint $table) {
                $table->json('used_by_users')->nullable()->after('used_by');
            });
        }

        // ============================================================
        // 2026_06_17_000007 — class_id FK on qr_invites
        // ============================================================
        if (!Schema::hasColumn('qr_invites', 'class_id')) {
            Schema::table('qr_invites', function (Blueprint $table) {
                $table->foreignId('class_id')->nullable()->after('created_by')
                    ->constrained('classes')->nullOnDelete();
                $table->index('class_id');
            });
        }

        // ============================================================
        // 2026_06_18_000002 — backfill class_year_id + fix FKs
        // ============================================================
        if (Schema::hasTable('class_years')) {
            DB::statement("
                UPDATE attendances a
                SET class_year_id = c.id
                FROM class_years cy
                INNER JOIN classes c ON c.church_id = cy.church_id AND c.name = cy.name
                WHERE a.class_year_id = cy.id
            ");
            DB::statement("
                UPDATE events e
                SET class_year_id = c.id
                FROM class_years cy
                INNER JOIN classes c ON c.church_id = cy.church_id AND c.name = cy.name
                WHERE e.class_year_id = cy.id
            ");
            DB::statement("
                UPDATE qr_invites qi
                SET class_year_id = c.id
                FROM class_years cy
                INNER JOIN classes c ON c.church_id = cy.church_id AND c.name = cy.name
                WHERE qi.class_year_id = cy.id
            ");
        }
        $this->fixForeignKey('attendances', 'class_year_id', 'classes');
        $this->fixForeignKey('events', 'class_year_id', 'classes');
        $this->fixForeignKey('qr_invites', 'class_year_id', 'classes');

        // ============================================================
        // 2026_06_18_000001 — fix feedback.class_year_id FK
        // ============================================================
        Schema::table('feedback', function (Blueprint $table) {
            $table->dropForeign(['class_year_id']);
            $table->foreign('class_year_id')->references('id')->on('classes')->nullOnDelete();
        });
    }

    private function fixForeignKey(string $table, string $column, string $referencedTable): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($column, $referencedTable) {
                $t->dropForeign([$column]);
                $t->foreign($column)->references('id')->on($referencedTable)->nullOnDelete();
            });
        } catch (\Exception $e) {
            // Foreign key may not exist — skip gracefully
        }
    }

    public function down(): void
    {
        // Batch 2 was never truly applied; no rollback needed
    }
};
