<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only run backfill if the old class_years table still exists
        if (!Schema::hasTable('class_years')) {
            goto fix_fks;
        }

        // --- Backfill attendances.class_year_id ---
        DB::statement("
            UPDATE attendances a
            SET class_year_id = c.id
            FROM class_years cy
            INNER JOIN classes c ON c.church_id = cy.church_id AND c.name = cy.name
            WHERE a.class_year_id = cy.id
        ");

        // --- Backfill events.class_year_id ---
        DB::statement("
            UPDATE events e
            SET class_year_id = c.id
            FROM class_years cy
            INNER JOIN classes c ON c.church_id = cy.church_id AND c.name = cy.name
            WHERE e.class_year_id = cy.id
        ");

        // --- Backfill qr_invites.class_year_id ---
        DB::statement("
            UPDATE qr_invites qi
            SET class_year_id = c.id
            FROM class_years cy
            INNER JOIN classes c ON c.church_id = cy.church_id AND c.name = cy.name
            WHERE qi.class_year_id = cy.id
        ");

        fix_fks:

        // --- Attendances FK ---
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['class_year_id']);
            $table->foreign('class_year_id')->references('id')->on('classes')->nullOnDelete();
        });

        // --- Events FK ---
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['class_year_id']);
            $table->foreign('class_year_id')->references('id')->on('classes')->nullOnDelete();
        });

        // --- QR Invites FK (class_year_id) ---
        Schema::table('qr_invites', function (Blueprint $table) {
            $table->dropForeign(['class_year_id']);
            $table->foreign('class_year_id')->references('id')->on('classes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['class_year_id']);
            $table->foreign('class_year_id')->references('id')->on('class_years')->nullOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['class_year_id']);
            $table->foreign('class_year_id')->references('id')->on('class_years')->nullOnDelete();
        });

        Schema::table('qr_invites', function (Blueprint $table) {
            $table->dropForeign(['class_year_id']);
            $table->foreign('class_year_id')->references('id')->on('class_years')->nullOnDelete();
        });
    }
};
